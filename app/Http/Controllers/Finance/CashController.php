<?php

namespace App\Http\Controllers\Finance;

use App\Domain\Cash\Actions\BuildCashReleaseBucketDetail;
use App\Domain\Cash\Actions\BuildCashReleaseBuckets;
use App\Domain\Cash\Actions\BuildMarketplacePaymentDetail;
use App\Domain\Cash\Actions\BuildOrderCashDiff;
use App\Domain\Cash\Actions\BuildOverdueReleaseCoverage;
use App\Domain\Cash\Actions\DetectOverdueReleases;
use App\Domain\Cash\Actions\SyncMarketplacePaymentForOrder;
use App\Domain\Cash\Support\CashMovementConcept;
use App\Domain\Cash\Support\CashReportDateWindows;
use App\Domain\Cash\Support\CashReportSyncProgress;
use App\Domain\Cash\Support\OverdueReleaseQuery;
use App\Domain\Cash\Support\ParseMercadoPagoReportCsv;
use App\Domain\Sales\Actions\RefreshCanonicalOrder;
use App\Domain\Shared\Support\BusinessDay;
use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Concerns\RespondsWithJsonPaginator;
use App\Http\Controllers\Controller;
use App\Jobs\ReconcileCashLedgerJob;
use App\Jobs\SyncMercadoPagoReportJob;
use App\Models\CashLedgerEntry;
use App\Models\CashReconciliationRun;
use App\Models\CashReportFile;
use App\Models\Connection;
use App\Models\MarketplacePayment;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CashController extends Controller
{
    use RespondsWithJsonPaginator;

    public function index(
        Request $request,
        BuildCashReleaseBuckets $releaseBuckets,
        DetectOverdueReleases $overdueReleases,
        BuildOverdueReleaseCoverage $releaseCoverage,
    ): Response|JsonResponse {
        $workspaceId = (int) TenantContext::id();
        $view = (string) $request->input('view', 'payments');
        if (! in_array($view, ['payments', 'ledger', 'releases', 'withdrawals', 'overdue'], true)) {
            $view = 'payments';
        }

        $overdueKind = trim((string) $request->input('kind', ''));
        if (! in_array($overdueKind, OverdueReleaseQuery::kinds(), true)) {
            $overdueKind = '';
        }

        $connectionId = $request->filled('connection_id') ? (int) $request->input('connection_id') : null;
        $wantsJson = $this->wantsJsonWithoutInertia($request);
        $overdueKpis = null;

        if ($view === 'overdue') {
            $result = $overdueReleases->execute(
                $workspaceId,
                $connectionId,
                $overdueKind !== '' ? $overdueKind : null,
                $request->input('q'),
                40,
                max(1, (int) $request->input('page', 1)),
                withKpis: ! $wantsJson,
            );
            $paginator = $result['paginator'];
            $overdueKpis = $result['kpis'];
        } else {
            $paginator = match ($view) {
                'ledger' => $this->ledgerPaginator($request, $workspaceId),
                'releases' => $releaseBuckets->execute(
                    $workspaceId,
                    $connectionId,
                    CarbonImmutable::now()->subDays(30)->startOfDay(),
                    CarbonImmutable::now()->endOfDay(),
                    40,
                    max(1, (int) $request->input('page', 1)),
                ),
                'withdrawals' => $this->withdrawalsPaginator($request, $workspaceId),
                default => $this->paymentsPaginator($request, $workspaceId),
            };
        }

        if ($wantsJson) {
            return $this->jsonPaginator($paginator);
        }

        $alertCount = MarketplacePayment::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('reconciliation_status', ['short', 'over', 'incomplete', 'pending'])
            ->count();

        $overdueCount = $overdueKpis['overdue']
            ?? app(OverdueReleaseQuery::class)->counts($workspaceId, $connectionId)['overdue'];

        $paymentsTotal = MarketplacePayment::query()->where('workspace_id', $workspaceId)->count();
        $ledgerTotal = CashLedgerEntry::query()->where('workspace_id', $workspaceId)->count();
        $withdrawalsTotal = CashLedgerEntry::query()
            ->where('workspace_id', $workspaceId)
            ->where('entry_type', 'withdrawal')
            ->count();

        return Inertia::render('Finance/Cash/Index', [
            'view' => $view,
            'rows' => $paginator,
            'filters' => [
                'view' => $view,
                'entry_type' => $request->input('entry_type'),
                'status' => $request->input('status'),
                'kind' => $overdueKind !== '' ? $overdueKind : null,
                'connection_id' => $request->input('connection_id'),
                'q' => $request->input('q'),
            ],
            'connections' => Connection::query()
                ->where('workspace_id', $workspaceId)
                ->where('status', 'active')
                ->orderBy('display_name')
                ->get(['id', 'display_name', 'color', 'provider', 'external_user_id']),
            'alert_count' => $alertCount,
            'overdue_count' => (int) $overdueCount,
            'overdue_kpis' => $overdueKpis,
            'release_coverage' => $releaseCoverage->execute($workspaceId, $connectionId),
            'payments_total' => $paymentsTotal,
            'ledger_total' => $ledgerTotal,
            'withdrawals_total' => $withdrawalsTotal,
        ]);
    }

    public function showEntry(CashLedgerEntry $entry): JsonResponse
    {
        $this->assertWorkspace($entry->workspace_id);
        $entry->load(['connection:id,display_name', 'reconciliationLinks.order', 'reconciliationLinks.marketplacePayment']);

        $lookupId = (string) ($entry->external_order_id ?: $entry->external_reference ?: '');
        $concept = CashMovementConcept::describe(
            $entry->transaction_type,
            $entry->entry_type,
            $lookupId,
            $entry->net_amount !== null ? (string) $entry->net_amount : null,
        );

        $resolvedExternal = $lookupId !== '' && CashMovementConcept::looksLikeMlOrderId($lookupId)
            ? $lookupId
            : null;
        if ($resolvedExternal === null && (
            $concept['key'] === CashMovementConcept::SHIPPING_CREDIT
            || CashMovementConcept::looksLikeShippingId($lookupId)
        )) {
            $resolvedExternal = CashLedgerEntry::query()
                ->where('workspace_id', $entry->workspace_id)
                ->where('connection_id', $entry->connection_id)
                ->where('provenance', 'mp_settlement_report')
                ->where(function ($q) use ($entry, $lookupId) {
                    if ($lookupId !== '') {
                        $q->where('external_reference', $lookupId)
                            ->orWhere('external_shipping_id', $lookupId);
                    }
                    if ($entry->external_source_id) {
                        $q->orWhere('external_source_id', $entry->external_source_id);
                    }
                })
                ->where(function ($q) {
                    $q->where('external_order_id', 'like', '20000%')
                        ->orWhereRaw('LENGTH(external_order_id) >= 15');
                })
                ->value('external_order_id');
            $resolvedExternal = is_string($resolvedExternal) && $resolvedExternal !== '' ? $resolvedExternal : null;
        }
        if ($resolvedExternal === null && $lookupId !== '' && $concept['key'] === CashMovementConcept::SALE) {
            $resolvedExternal = $lookupId;
        }

        $localOrder = null;
        if ($resolvedExternal !== null) {
            $localOrder = Order::query()
                ->where('workspace_id', $entry->workspace_id)
                ->where('connection_id', $entry->connection_id)
                ->where('external_order_id', $resolvedExternal)
                ->first(['id', 'external_order_id']);
        }

        $orders = [];
        foreach ($entry->reconciliationLinks as $link) {
            if ($link->order_id || $link->marketplace_payment_id) {
                $orders[] = [
                    'order_id' => $link->order_id,
                    'external_order_id' => $link->order?->external_order_id,
                    'marketplace_payment_id' => $link->marketplace_payment_id,
                    'external_payment_id' => $link->marketplacePayment?->external_payment_id,
                    'allocated_amount' => $link->allocated_amount,
                    'expected_amount' => $link->expected_amount,
                    'diff_amount' => $link->diff_amount,
                    'status' => $link->status,
                    'match_method' => $link->match_method,
                ];
            }
        }

        return response()->json([
            'kind' => $entry->entry_type === 'withdrawal' ? 'withdrawal' : 'ledger',
            'entry' => $entry,
            'concept' => $concept['key'],
            'concept_label' => $concept['label'],
            'concept_hint' => $concept['hint'],
            'local_order_id' => $localOrder?->id,
            'resolved_external_order_id' => $resolvedExternal,
            'covered_orders' => $orders,
            'is_bank_payout' => $entry->entry_type === 'withdrawal',
            'payout_id' => $entry->external_source_id,
        ]);
    }

    public function showPayment(MarketplacePayment $payment, BuildMarketplacePaymentDetail $builder): JsonResponse
    {
        $this->assertWorkspace($payment->workspace_id);

        return response()->json($builder->execute($payment));
    }

    public function showReleaseBucket(Request $request, BuildCashReleaseBucketDetail $builder): JsonResponse
    {
        $workspaceId = (int) TenantContext::id();
        $bucketId = (string) $request->query('id', '');
        if ($bucketId === '' && $request->filled('connection_id') && $request->filled('hour')) {
            $bucketId = ((int) $request->input('connection_id')).'|'.(string) $request->input('hour');
        }

        try {
            return response()->json($builder->execute($workspaceId, $bucketId));
        } catch (InvalidArgumentException) {
            abort(404);
        }
    }

    public function showOrderDiff(Order $order, BuildOrderCashDiff $builder): JsonResponse
    {
        $this->assertWorkspace($order->workspace_id);

        return response()->json([
            'order_id' => $order->id,
            'external_order_id' => $order->external_order_id,
            'diff' => $builder->execute($order),
        ]);
    }

    public function fetchOrder(
        Request $request,
        RefreshCanonicalOrder $refresh,
        SyncMarketplacePaymentForOrder $syncPayment,
    ): JsonResponse {
        $workspaceId = (int) TenantContext::id();
        $data = $request->validate([
            'connection_id' => ['required', 'integer'],
            'external_order_id' => ['required', 'string', 'max:64'],
        ]);

        $connection = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->where('id', (int) $data['connection_id'])
            ->firstOrFail();

        $externalOrderId = trim((string) $data['external_order_id']);
        if (CashMovementConcept::looksLikeShippingId($externalOrderId)) {
            $isPaymentId = MarketplacePayment::query()
                ->where('workspace_id', $workspaceId)
                ->where('connection_id', $connection->id)
                ->where('external_payment_id', $externalOrderId)
                ->exists()
                || CashLedgerEntry::query()
                    ->where('connection_id', $connection->id)
                    ->where('external_source_id', $externalOrderId)
                    ->where(function ($q) {
                        $q->whereIn('transaction_type', ['PAYMENT', 'SETTLEMENT'])
                            ->orWhere('entry_type', 'settlement');
                    })
                    ->where(function ($q) {
                        $q->whereNull('transaction_type')
                            ->orWhere(function ($q2) {
                                $q2->where('transaction_type', 'not like', '%SHIPPING%')
                                    ->where('transaction_type', 'not like', 'RESERVE_%');
                            });
                    })
                    ->exists();

            if ($isPaymentId) {
                $resolvedFromPayment = MarketplacePayment::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('connection_id', $connection->id)
                    ->where('external_payment_id', $externalOrderId)
                    ->with('order:id,external_order_id')
                    ->first()?->order?->external_order_id;
                if (is_string($resolvedFromPayment) && CashMovementConcept::looksLikeMlOrderId($resolvedFromPayment)) {
                    $externalOrderId = $resolvedFromPayment;
                }
            } else {
                return response()->json([
                    'message' => 'Ese ID es un envío del comprador, no una orden de Mercado Libre.',
                ], 422);
            }
        }

        try {
            $order = $refresh->executeForConnection($connection, $externalOrderId);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $payments = $syncPayment->execute($order, allowFetch: true);

        return response()->json([
            'order_id' => $order->id,
            'external_order_id' => $order->external_order_id,
            'status' => $order->status,
            'payments_synced' => count($payments),
            'payment_ids' => collect($payments)->pluck('id')->values()->all(),
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        $workspaceId = (int) TenantContext::id();
        $data = $request->validate([
            'connection_id' => ['nullable', 'integer'],
            'report_kind' => ['nullable', 'string', 'in:settlement,release'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $kind = (string) ($data['report_kind'] ?? 'settlement');
        if (! in_array($kind, ['settlement', 'release'], true)) {
            $kind = 'settlement';
        }
        $kinds = $kind === 'settlement' ? ['settlement', 'release'] : [$kind];

        $connections = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider', 'mercadolibre')
            ->where('status', 'active')
            ->when(
                isset($data['connection_id']),
                fn ($q) => $q->where('id', (int) $data['connection_id']),
            )
            ->orderBy('id')
            ->get();

        if ($connections->isEmpty()) {
            return response()->json(['message' => 'No hay conexión activa para sincronizar.'], 422);
        }

        $tz = BusinessDay::timezone();
        $from = isset($data['from']) ? CarbonImmutable::parse((string) $data['from'], $tz)->startOfDay() : null;
        $to = isset($data['to']) ? CarbonImmutable::parse((string) $data['to'], $tz)->endOfDay() : null;
        if ($from !== null && $to !== null && $from->gt($to)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        $maxDays = max(1, (int) config('finance.cash.release_sync_max_days', 14));
        if ($from !== null && $to !== null) {
            $spanDays = (int) $from->startOfDay()->diffInDays($to->startOfDay(), true) + 1;
            if ($spanDays > $maxDays) {
                $from = $to->startOfDay()->subDays($maxDays - 1)->startOfDay();
            }
        }

        $chunkDays = max(1, (int) config('finance.cash.release_sync_chunk_days', 2));
        $windows = ($from !== null && $to !== null)
            ? CashReportDateWindows::chunk($from, $to, $chunkDays, $tz)
            : [null];

        $firstProgress = null;
        $queued = [];
        foreach ($connections as $connection) {
            $progress = CashReportSyncProgress::start($workspaceId, (int) $connection->id, $kinds);
            $firstProgress ??= $progress;

            foreach ($windows as $window) {
                $windowFrom = is_array($window) ? $window[0]->toIso8601String() : null;
                $windowTo = is_array($window) ? $window[1]->toIso8601String() : null;
                foreach ($kinds as $reportKind) {
                    SyncMercadoPagoReportJob::dispatch(
                        $workspaceId,
                        (int) $connection->id,
                        $reportKind,
                        $windowFrom,
                        $windowTo,
                        false,
                    );
                    $queued[] = [
                        'connection_id' => (int) $connection->id,
                        'kind' => $reportKind,
                        'from' => $windowFrom,
                        'to' => $windowTo,
                    ];
                }
            }

            ReconcileCashLedgerJob::dispatch(
                $workspaceId,
                (int) $connection->id,
                $from?->toIso8601String(),
                $to?->toIso8601String(),
            );
        }

        return response()->json([
            'queued' => true,
            'report_kind' => $kind,
            'kinds' => $kinds,
            'windows' => count($windows),
            'connections' => $connections->pluck('id')->values()->all(),
            'from' => $from?->timezone($tz)->toDateString(),
            'to' => $to?->timezone($tz)->toDateString(),
            'jobs' => $queued,
            'progress' => $firstProgress,
        ]);
    }

    public function syncStatus(Request $request): JsonResponse
    {
        $workspaceId = (int) TenantContext::id();
        $connectionId = (int) $request->input('connection_id');
        Connection::query()
            ->where('workspace_id', $workspaceId)
            ->where('id', $connectionId)
            ->firstOrFail();

        $progress = CashReportSyncProgress::get($workspaceId, $connectionId);
        $officialReleaseRows = CashLedgerEntry::query()
            ->where('workspace_id', $workspaceId)
            ->where('connection_id', $connectionId)
            ->where('provenance', 'mp_release_report')
            ->count();

        return response()->json([
            'progress' => $progress,
            'stats' => [
                'release_ledger_rows' => $officialReleaseRows,
                'settlement_ledger_rows' => CashLedgerEntry::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('connection_id', $connectionId)
                    ->where('provenance', 'mp_settlement_report')
                    ->count(),
            ],
            'report_files' => $this->serializeReportFiles(
                CashReportFile::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('connection_id', $connectionId)
                    ->orderByDesc('id')
                    ->limit(10)
                    ->get()
            ),
        ]);
    }

    public function reportFiles(Request $request): JsonResponse
    {
        $workspaceId = (int) TenantContext::id();
        $connectionId = (int) $request->input('connection_id');
        Connection::query()
            ->where('workspace_id', $workspaceId)
            ->where('id', $connectionId)
            ->firstOrFail();

        $query = CashReportFile::query()
            ->where('workspace_id', $workspaceId)
            ->where('connection_id', $connectionId)
            ->orderByDesc('id');

        if ($request->filled('report_kind')) {
            $query->where('report_kind', (string) $request->input('report_kind'));
        }

        return response()->json([
            'files' => $this->serializeReportFiles($query->limit(20)->get()),
        ]);
    }

    public function downloadReportFile(CashReportFile $file): StreamedResponse
    {
        $this->assertWorkspace((int) $file->workspace_id);
        abort_unless($file->existsOnDisk(), 404);

        $downloadName = $file->remote_file_name !== ''
            ? $file->remote_file_name
            : basename($file->storage_path);

        return Storage::disk('local')->download($file->storage_path, $downloadName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function previewReportFile(Request $request, CashReportFile $file, ParseMercadoPagoReportCsv $parser): JsonResponse
    {
        $this->assertWorkspace((int) $file->workspace_id);
        abort_unless($file->existsOnDisk(), 404);

        $limit = max(1, min(100, (int) $request->input('limit', 50)));
        $csv = (string) Storage::disk('local')->get($file->storage_path);
        $rows = $parser->execute($csv);
        $sample = array_slice($rows, 0, $limit);
        $headers = $sample !== [] ? array_keys($sample[0]) : [];

        return response()->json([
            'file' => $this->serializeReportFile($file),
            'headers' => $headers,
            'rows' => $sample,
            'truncated' => count($rows) > $limit,
            'total_rows' => count($rows),
        ]);
    }

    public function latestRun(): JsonResponse
    {
        $workspaceId = (int) TenantContext::id();
        $run = CashReconciliationRun::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('id')
            ->first();

        return response()->json(['run' => $run]);
    }

    private function paymentsPaginator(Request $request, int $workspaceId)
    {
        $query = MarketplacePayment::query()
            ->where('workspace_id', $workspaceId)
            ->with([
                'connection:id,display_name,color,provider,external_user_id',
                'order:id,external_order_id',
            ])
            ->orderByDesc('paid_at')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('reconciliation_status', (string) $request->string('status'));
        }
        if ($request->filled('connection_id')) {
            $query->where('connection_id', (int) $request->input('connection_id'));
        }
        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($b) use ($q) {
                $b->where('external_payment_id', 'like', '%'.$q.'%')
                    ->orWhereHas('order', fn ($oq) => $oq->where('external_order_id', 'like', '%'.$q.'%'));
            });
        }

        $paginator = $query->paginate(40)->withQueryString();
        $orderExternals = collect($paginator->items())
            ->map(fn (MarketplacePayment $p) => $p->order?->external_order_id)
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        $shippingExternals = $this->ordersWithBuyerShipping($workspaceId, $orderExternals);

        return $paginator->through(function (MarketplacePayment $payment) use ($shippingExternals) {
            $ext = $payment->order?->external_order_id;
            $payment->setAttribute(
                'has_shipping_credit',
                is_string($ext) && $ext !== '' && isset($shippingExternals[$ext]),
            );
            $payment->setAttribute('in_mediation', $payment->status === 'in_mediation');

            return $payment;
        });
    }

    /**
     * @param  list<string>  $orderExternals
     * @return array<string, true>
     */
    private function ordersWithBuyerShipping(int $workspaceId, array $orderExternals): array
    {
        if ($orderExternals === []) {
            return [];
        }

        $flagged = [];
        $shippingRows = CashLedgerEntry::query()
            ->where('workspace_id', $workspaceId)
            ->where(function ($q) {
                $q->whereIn('transaction_type', ['SHIPPING', 'SETTLEMENT_SHIPPING'])
                    ->orWhere(function ($q2) {
                        $q2->where('transaction_type', 'like', '%SHIPPING%')
                            ->where('transaction_type', 'not like', 'RESERVE_%')
                            ->where('transaction_type', 'not like', 'REFUND_%');
                    });
            })
            ->whereIn('external_order_id', $orderExternals)
            ->pluck('external_order_id');
        foreach ($shippingRows as $id) {
            if (is_string($id) && $id !== '') {
                $flagged[$id] = true;
            }
        }

        $settlements = CashLedgerEntry::query()
            ->where('workspace_id', $workspaceId)
            ->where('provenance', 'mp_settlement_report')
            ->whereIn('external_order_id', $orderExternals)
            ->where(function ($q) {
                $q->whereIn('transaction_type', ['SHIPPING', 'SETTLEMENT_SHIPPING'])
                    ->orWhereNotNull('external_shipping_id')
                    ->orWhereNotNull('external_reference');
            })
            ->get(['external_order_id', 'external_reference', 'external_shipping_id', 'external_source_id']);

        $shortToOrder = [];
        foreach ($settlements as $row) {
            $ml = (string) $row->external_order_id;
            if (! CashMovementConcept::looksLikeMlOrderId($ml)) {
                continue;
            }
            foreach ([$row->external_shipping_id, $row->external_reference, $row->external_source_id] as $candidate) {
                if (is_string($candidate) && CashMovementConcept::looksLikeShippingId($candidate)) {
                    $shortToOrder[$candidate] = $ml;
                }
            }
        }

        if ($shortToOrder === []) {
            return $flagged;
        }

        $ids = array_keys($shortToOrder);
        $releases = CashLedgerEntry::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('transaction_type', ['SHIPPING', 'SETTLEMENT_SHIPPING'])
            ->where(function ($q) use ($ids) {
                $q->whereIn('external_order_id', $ids)
                    ->orWhereIn('external_shipping_id', $ids)
                    ->orWhereIn('external_reference', $ids)
                    ->orWhereIn('external_source_id', $ids);
            })
            ->get(['external_order_id', 'external_shipping_id', 'external_reference', 'external_source_id']);

        foreach ($releases as $rel) {
            foreach ([$rel->external_order_id, $rel->external_shipping_id, $rel->external_reference, $rel->external_source_id] as $candidate) {
                if (is_string($candidate) && isset($shortToOrder[$candidate])) {
                    $flagged[$shortToOrder[$candidate]] = true;
                }
            }
        }

        return $flagged;
    }

    private function ledgerPaginator(Request $request, int $workspaceId)
    {
        $query = CashLedgerEntry::query()
            ->where('workspace_id', $workspaceId)
            ->with(['connection:id,display_name,color,provider,external_user_id'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if ($request->filled('entry_type')) {
            $query->where('entry_type', (string) $request->string('entry_type'));
        }
        if ($request->filled('connection_id')) {
            $query->where('connection_id', (int) $request->input('connection_id'));
        }
        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($b) use ($q) {
                $b->where('external_source_id', 'like', '%'.$q.'%')
                    ->orWhere('external_order_id', 'like', '%'.$q.'%')
                    ->orWhere('external_reference', 'like', '%'.$q.'%')
                    ->orWhere('idempotency_key', 'like', '%'.$q.'%');
            });
        }

        $paginator = $query->paginate(40)->withQueryString();
        $externalIds = collect($paginator->items())
            ->pluck('external_order_id')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        $orderMap = [];
        if ($externalIds !== []) {
            $orderMap = Order::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('external_order_id', $externalIds)
                ->pluck('id', 'external_order_id')
                ->all();
        }

        return $paginator->through(function (CashLedgerEntry $entry) use ($orderMap) {
            $lookup = (string) ($entry->external_order_id ?: $entry->external_reference ?: '');
            $concept = CashMovementConcept::describe(
                $entry->transaction_type,
                $entry->entry_type,
                $lookup,
                $entry->net_amount !== null ? (string) $entry->net_amount : null,
            );
            $entry->setAttribute('concept', $concept['key']);
            $entry->setAttribute('concept_label', $concept['label']);
            $entry->setAttribute('concept_hint', $concept['hint']);
            $entry->setAttribute(
                'local_order_id',
                $lookup !== '' ? ($orderMap[$lookup] ?? null) : null,
            );

            return $entry;
        });
    }

    private function withdrawalsPaginator(Request $request, int $workspaceId)
    {
        $query = CashLedgerEntry::query()
            ->where('workspace_id', $workspaceId)
            ->where('entry_type', 'withdrawal')
            ->with(['connection:id,display_name,color,provider,external_user_id'])
            ->withCount('reconciliationLinks')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if ($request->filled('connection_id')) {
            $query->where('connection_id', (int) $request->input('connection_id'));
        }
        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($b) use ($q) {
                $b->where('external_source_id', 'like', '%'.$q.'%')
                    ->orWhere('external_reference', 'like', '%'.$q.'%');
            });
        }

        return $query->paginate(40)->withQueryString();
    }

    private function assertWorkspace(int $workspaceId): void
    {
        abort_unless($workspaceId === (int) TenantContext::id(), 404);
    }

    /**
     * @param  Collection<int, CashReportFile>|iterable<CashReportFile>  $files
     * @return list<array<string, mixed>>
     */
    private function serializeReportFiles(iterable $files): array
    {
        $out = [];
        foreach ($files as $file) {
            $out[] = $this->serializeReportFile($file);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeReportFile(CashReportFile $file): array
    {
        return [
            'id' => $file->id,
            'connection_id' => $file->connection_id,
            'report_kind' => $file->report_kind,
            'remote_file_name' => $file->remote_file_name,
            'report_shape' => $file->report_shape,
            'rows_count' => $file->rows_count,
            'bytes' => $file->bytes,
            'begin_date' => $file->begin_date?->toIso8601String(),
            'end_date' => $file->end_date?->toIso8601String(),
            'report_id' => $file->report_id,
            'created_at' => $file->created_at?->toIso8601String(),
            'download_url' => route('finance.cash.report-files.download', $file),
            'preview_url' => route('finance.cash.report-files.preview', $file),
        ];
    }
}
