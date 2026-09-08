<?php

namespace App\Http\Controllers;

use App\Domain\Ads\Actions\AttributeAdvertisingToOrders;
use App\Domain\Cash\Actions\BuildOverdueReleaseCoverage;
use App\Domain\Cash\Support\OverdueReleaseQuery;
use App\Domain\Catalog\Services\ProductSalesSummaryService;
use App\Domain\Finance\Actions\RefreshExpectedOrderFinance;
use App\Domain\Inventory\Actions\ResolveOrderStockEvidence;
use App\Domain\Inventory\Actions\RestockOrderFromReturn;
use App\Domain\Inventory\Support\FullStockOperationType;
use App\Domain\PostSale\Actions\BuildOrderPostSaleSummary;
use App\Domain\PostSale\Actions\DownloadClaimAttachment;
use App\Domain\PostSale\Actions\ResolveOrderPostSaleOutcome;
use App\Domain\PostSale\Actions\SendClaimMessage;
use App\Domain\PostSale\Actions\SendOrderMessage;
use App\Domain\PostSale\Actions\SyncClaimMessages;
use App\Domain\PostSale\Actions\SyncOrderMessages;
use App\Domain\Sales\Actions\BuildBuyerInsights;
use App\Domain\Sales\Actions\BuildTodaySalesSummary;
use App\Domain\Sales\Actions\FetchOrderSatInvoice;
use App\Domain\Sales\Actions\RefreshCanonicalOrder;
use App\Domain\Sales\Support\BuyerPresentation;
use App\Domain\Sales\Support\SatInvoiceFileCodec;
use App\Domain\Shared\Support\BusinessDay;
use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Concerns\RespondsWithFilteredSelection;
use App\Http\Controllers\Concerns\RespondsWithJsonPaginator;
use App\Http\Filters\Orders\OrderFilterRegistry;
use App\Http\Requests\Orders\IndexFilterRequest;
use App\Models\AdSpendDaily;
use App\Models\Claim;
use App\Models\Connection;
use App\Models\FinancialEvent;
use App\Models\FullStockOperation;
use App\Models\Order;
use App\Models\OrderMessage;
use App\Models\Reservation;
use App\Models\Shipment;
use App\Services\Listings\FilteredSelectionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

class OrdersController extends Controller
{
    use RespondsWithFilteredSelection;
    use RespondsWithJsonPaginator;

    public function index(IndexFilterRequest $request, OrderFilterRegistry $filterRegistry): Response|JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $filters = $request->filters();

        $query = $this->baseOrdersQuery($workspaceId);
        $filterRegistry->apply($query, $filters);
        $query->orderByDesc('ordered_at')->orderByDesc('id');

        $orders = $query->paginate(25)->withQueryString();
        $orders->getCollection()->transform(fn (Order $order) => $this->transformOrderListRow($order));
        $this->enrichProductSalesCounts($workspaceId, $orders->getCollection());
        $this->enrichPackGrouping($orders->getCollection());
        $this->enrichReleaseIssue($orders->getCollection(), $filters['release_issue'] ?? null);

        if ($this->wantsJsonWithoutInertia($request)) {
            return $this->jsonPaginator($orders);
        }

        $overdueReleaseCount = Order::query()->where('workspace_id', $workspaceId);
        if (! empty($filters['connection_id'])) {
            $overdueReleaseCount->where('connection_id', (int) $filters['connection_id']);
        }
        app(OverdueReleaseQuery::class)->constrain($overdueReleaseCount, OverdueReleaseQuery::KIND_OVERDUE);

        $statusCounts = Order::query()
            ->where('workspace_id', $workspaceId)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $connections = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->orderBy('provider')
            ->get(['id', 'provider', 'external_user_id', 'display_name', 'color', 'status']);

        $resolvedStatus = $filters['status'];
        if (($filters['tab'] ?? 'all') !== 'all' && ! $resolvedStatus) {
            $resolvedStatus = match ($filters['tab']) {
                'paid' => 'paid',
                'pending' => 'pending',
                'shipped', 'in_transit' => 'shipped',
                'delivered' => 'delivered',
                'cancelled' => 'cancelled',
                default => null,
            };
        }

        $todaySales = app(BuildTodaySalesSummary::class)->execute(
            $workspaceId,
            $filters['connection_id'] ?? null,
        );

        $releaseCoverage = app(BuildOverdueReleaseCoverage::class)->execute(
            $workspaceId,
            $filters['connection_id'] ?? null,
        );

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'status' => $resolvedStatus ?? '',
                'connection_id' => $filters['connection_id'] ?? null,
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
                'tab' => $filters['tab'] ?? 'all',
                'release_issue' => $filters['release_issue'] ?? null,
            ],
            'status_counts' => $statusCounts,
            'overdue_release_count' => $overdueReleaseCount->count(),
            'release_coverage' => $releaseCoverage,
            'connections' => $connections,
            'today_sales' => $todaySales,
        ]);
    }

    public function allIds(IndexFilterRequest $request, OrderFilterRegistry $filterRegistry): JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $query = Order::query()->where('workspace_id', $workspaceId);
        $filterRegistry->apply($query, $request->filters());

        return $this->selectionAllIds($query);
    }

    public function filteredSums(IndexFilterRequest $request, OrderFilterRegistry $filterRegistry): JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $query = Order::query()->where('workspace_id', $workspaceId);
        $filterRegistry->apply($query, $request->filters());

        $count = (clone $query)->toBase()->getCountForPagination();
        if ($count > FilteredSelectionService::MAX_IDS) {
            return response()->json([
                'message' => 'Hay más de '.number_format(FilteredSelectionService::MAX_IDS).' registros. Aplica más filtros para calcular totales.',
                'total_count' => $count,
                'max' => FilteredSelectionService::MAX_IDS,
            ], 422);
        }

        $orderTotals = (clone $query)
            ->reorder()
            ->toBase()
            ->selectRaw('COALESCE(SUM(orders.total_amount), 0) as total_amount')
            ->first();

        $orderIds = (clone $query)->select('orders.id');

        $latestExpected = DB::table('profit_snapshots as ps')
            ->selectRaw('MAX(ps.id) as id')
            ->where('ps.stage', 'expected')
            ->whereIn('ps.order_id', $orderIds)
            ->groupBy('ps.order_id');

        $driver = DB::connection()->getDriverName();
        $taxesExpr = $driver === 'sqlite'
            ? "COALESCE(SUM(CAST(json_extract(ps.payload, '$.taxes_retention_total') AS REAL)), 0)"
            : "COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(ps.payload, '$.taxes_retention_total')) AS DECIMAL(20,6))), 0)";
        $netExpr = $driver === 'sqlite'
            ? "COALESCE(SUM(CAST(COALESCE(json_extract(ps.payload, '$.net_received_amount'), json_extract(ps.payload, '$.marketplace_net_amount')) AS REAL)), 0)"
            : "COALESCE(SUM(CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ps.payload, '$.net_received_amount')), JSON_UNQUOTE(JSON_EXTRACT(ps.payload, '$.marketplace_net_amount'))) AS DECIMAL(20,6))), 0)";

        $profitTotals = DB::table('profit_snapshots as ps')
            ->whereIn('ps.id', $latestExpected)
            ->selectRaw("
                COALESCE(SUM(ps.revenue_amount), 0) as profit_revenue,
                COALESCE(SUM(ps.fees_amount), 0) as profit_fees,
                {$taxesExpr} as profit_taxes,
                {$netExpr} as profit_marketplace_net,
                COALESCE(SUM(ps.cogs_amount), 0) as profit_cogs,
                COALESCE(SUM(ps.profit_amount), 0) as profit_amount
            ")
            ->first();

        $revenue = (float) ($profitTotals->profit_revenue ?? 0);
        $fees = (float) ($profitTotals->profit_fees ?? 0);
        $taxes = (float) ($profitTotals->profit_taxes ?? 0);
        $marketplaceNet = (float) ($profitTotals->profit_marketplace_net ?? 0);
        if ($marketplaceNet === 0.0 && ($revenue !== 0.0 || $fees !== 0.0 || $taxes !== 0.0)) {
            $marketplaceNet = $revenue - $fees - $taxes;
        }

        return response()->json([
            'sums_by_key' => [
                'total_amount' => (float) ($orderTotals->total_amount ?? 0),
                'profit_revenue' => $revenue,
                'profit_fees' => $fees,
                'profit_taxes' => $taxes,
                'profit_marketplace_net' => $marketplaceNet,
                'profit_cogs' => (float) ($profitTotals->profit_cogs ?? 0),
                'profit_amount' => (float) ($profitTotals->profit_amount ?? 0),
            ],
            'total_count' => $count,
        ]);
    }

    public function indexListUpdates(IndexFilterRequest $request, OrderFilterRegistry $filterRegistry): JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $sinceId = (int) $request->input('since_id', 0);
        if ($sinceId <= 0) {
            return response()->json(['message' => 'since_id requerido'], 422);
        }

        $filters = $request->filters();

        $idsQuery = Order::query()->where('workspace_id', $workspaceId);
        $filterRegistry->apply($idsQuery, $filters);

        /** @var Collection<int, int|string> $ids */
        $ids = $idsQuery
            ->where('id', '>', $sinceId)
            ->orderByDesc('id')
            ->limit(10)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return response()->json(['data' => [], 'count' => 0]);
        }

        $rows = $this->baseOrdersQuery($workspaceId)
            ->whereIn('id', $ids)
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Order $order) => $this->transformOrderListRow($order))
            ->values();
        $this->enrichProductSalesCounts($workspaceId, $rows);
        $this->enrichPackGrouping($rows);
        $this->enrichReleaseIssue($rows, $filters['release_issue'] ?? null);

        return response()->json([
            'data' => $rows,
            'count' => $rows->count(),
        ]);
    }

    public function show(Request $request, Order $order): Response|JsonResponse
    {
        abort_unless((int) $order->workspace_id === TenantContext::id(), 404);

        $payload = $this->buildShowPayload($order);

        if (($request->wantsJson() || $request->expectsJson()) && ! $request->header('X-Inertia')) {
            return response()->json($payload);
        }

        return Inertia::render('Orders/Show', $payload);
    }

    public function syncNow(Request $request, Order $order, RefreshCanonicalOrder $refresh): JsonResponse|RedirectResponse
    {
        abort_unless((int) $order->workspace_id === TenantContext::id(), 404);

        try {
            $order = $refresh->execute($order);
        } catch (InvalidArgumentException $e) {
            if (($request->wantsJson() || $request->expectsJson()) && ! $request->header('X-Inertia')) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return redirect()
                ->route('orders.show', $order)
                ->with('error', $e->getMessage());
        } catch (RuntimeException $e) {
            if (($request->wantsJson() || $request->expectsJson()) && ! $request->header('X-Inertia')) {
                return response()->json(['message' => $e->getMessage()], 502);
            }

            return redirect()
                ->route('orders.show', $order)
                ->with('error', $e->getMessage());
        }

        $payload = $this->buildShowPayload($order);

        if (($request->wantsJson() || $request->expectsJson()) && ! $request->header('X-Inertia')) {
            return response()->json($payload);
        }

        return redirect()
            ->route('orders.show', $order)
            ->with('success', 'Orden actualizada desde Mercado Libre.');
    }

    public function reservations(Order $order): JsonResponse
    {
        abort_unless((int) $order->workspace_id === TenantContext::id(), 404);

        $evidence = app(ResolveOrderStockEvidence::class)->execute($order);

        $reservations = $evidence['reservations']
            ->load([
                'warehouse:id,code,name',
                'orderLine:id,sku,title,quantity,match_status',
                'variant:id,sku',
            ])
            ->map(fn (Reservation $reservation) => [
                'id' => $reservation->id,
                'status' => $reservation->status,
                'quantity' => $reservation->quantity,
                'reserved_at' => $reservation->reserved_at,
                'released_at' => $reservation->released_at,
                'variant_id' => $reservation->variant_id,
                'warehouse' => $reservation->warehouse
                    ? [
                        'id' => $reservation->warehouse->id,
                        'code' => $reservation->warehouse->code,
                        'name' => $reservation->warehouse->name,
                    ]
                    : null,
                'order_line' => $reservation->orderLine
                    ? [
                        'id' => $reservation->orderLine->id,
                        'sku' => $reservation->orderLine->sku,
                        'title' => $reservation->orderLine->title,
                        'quantity' => $reservation->orderLine->quantity,
                        'match_status' => $reservation->orderLine->match_status,
                    ]
                    : null,
                'variant_sku' => $reservation->variant?->sku,
            ])
            ->values();

        $fullOperations = $evidence['full_operations']
            ->load(['variant:id,sku'])
            ->map(fn (FullStockOperation $op) => $this->serializeOrderFullOperation($op))
            ->values();

        return response()->json([
            'order' => [
                'id' => $order->id,
                'external_order_id' => $order->external_order_id,
                'status' => $order->status,
            ],
            'reservations' => $reservations,
            'full_operations' => $fullOperations,
        ]);
    }

    public function buyer(Order $order, BuildBuyerInsights $buildBuyerInsights): JsonResponse
    {
        abort_unless((int) $order->workspace_id === TenantContext::id(), 404);

        try {
            $payload = $buildBuyerInsights->execute($order);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($payload);
    }

    public function invoice(Order $order, FetchOrderSatInvoice $fetch): JsonResponse
    {
        abort_unless((int) $order->workspace_id === TenantContext::id(), 404);

        $payload = $fetch->execute($order, includeFiles: true);

        return response()->json($payload);
    }

    public function invoicePdf(Order $order, FetchOrderSatInvoice $fetch): HttpResponse
    {
        abort_unless((int) $order->workspace_id === TenantContext::id(), 404);

        return $this->invoiceBinary($order, $fetch, 'pdf');
    }

    public function invoiceXml(Order $order, FetchOrderSatInvoice $fetch): HttpResponse
    {
        abort_unless((int) $order->workspace_id === TenantContext::id(), 404);

        return $this->invoiceBinary($order, $fetch, 'xml');
    }

    public function messages(Order $order, SyncOrderMessages $sync, Request $request): JsonResponse
    {
        abort_unless((int) $order->workspace_id === TenantContext::id(), 404);

        $refresh = ! $request->boolean('cached_only');

        try {
            $payload = $sync->execute($order, refreshFromProvider: $refresh);
        } catch (RuntimeException $e) {
            // Still return local mirror if provider sync fails.
            $local = $sync->execute($order, refreshFromProvider: false);

            return response()->json([
                'order' => [
                    'id' => $order->id,
                    'external_order_id' => $order->external_order_id,
                    'status' => $order->status,
                    'messaging_pack_id' => $order->messagingPackExternalId(),
                ],
                'messages' => $local['messages'],
                'conversation_status' => $order->meta['messaging']['conversation_status'] ?? null,
                'seller_max_message_length' => $order->meta['messaging']['seller_max_message_length'] ?? 350,
                'warning' => $e->getMessage(),
            ], $local['messages']->isEmpty() ? 502 : 200);
        }

        return response()->json([
            'order' => [
                'id' => $order->id,
                'external_order_id' => $order->external_order_id,
                'status' => $order->status,
                'messaging_pack_id' => $order->messagingPackExternalId(),
            ],
            'messages' => $payload['messages'],
            'conversation_status' => $payload['conversation_status']
                ?? ($order->fresh()->meta['messaging']['conversation_status'] ?? null),
            'seller_max_message_length' => $payload['seller_max_message_length'] ?? 350,
        ]);
    }

    public function sendMessage(Request $request, Order $order, SendOrderMessage $send, SyncOrderMessages $sync): JsonResponse
    {
        abort_unless((int) $order->workspace_id === TenantContext::id(), 404);

        $data = $request->validate([
            'text' => ['required', 'string', 'min:1', 'max:350'],
        ]);

        try {
            $send->execute($order, $data['text']);
            $payload = $sync->execute($order, refreshFromProvider: false);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'order' => [
                'id' => $order->id,
                'external_order_id' => $order->external_order_id,
                'status' => $order->status,
                'messaging_pack_id' => $order->messagingPackExternalId(),
            ],
            'messages' => $payload['messages'],
            'conversation_status' => $order->fresh()->meta['messaging']['conversation_status'] ?? null,
            'seller_max_message_length' => $order->meta['messaging']['seller_max_message_length'] ?? 350,
        ]);
    }

    public function claimMessages(
        Order $order,
        Claim $claim,
        SyncClaimMessages $sync,
        Request $request,
    ): JsonResponse {
        abort_unless((int) $order->workspace_id === TenantContext::id(), 404);
        abort_unless(
            (int) $claim->workspace_id === (int) $order->workspace_id
            && (int) $claim->order_id === (int) $order->id,
            404,
        );

        $refresh = ! $request->boolean('cached_only');

        try {
            $payload = $sync->execute($claim, refreshFromProvider: $refresh);
        } catch (RuntimeException $e) {
            $local = $sync->execute($claim, refreshFromProvider: false);

            return response()->json([
                'claim' => $this->serializeClaim($local['claim']),
                'messages' => $local['messages'],
                'warning' => $e->getMessage(),
            ], $local['messages']->isEmpty() ? 502 : 200);
        }

        return response()->json([
            'claim' => $this->serializeClaim($payload['claim']),
            'messages' => $payload['messages'],
        ]);
    }

    public function sendClaimMessage(
        Request $request,
        Order $order,
        Claim $claim,
        SendClaimMessage $send,
        SyncClaimMessages $sync,
    ): JsonResponse {
        abort_unless((int) $order->workspace_id === TenantContext::id(), 404);
        abort_unless(
            (int) $claim->workspace_id === (int) $order->workspace_id
            && (int) $claim->order_id === (int) $order->id,
            404,
        );

        $data = $request->validate([
            'text' => ['required', 'string', 'min:1', 'max:3500'],
        ]);

        try {
            $send->execute($claim, $data['text']);
            $payload = $sync->execute($claim, refreshFromProvider: false);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'claim' => $this->serializeClaim($payload['claim']),
            'messages' => $payload['messages'],
        ]);
    }

    public function claimAttachment(
        Order $order,
        Claim $claim,
        string $filename,
        DownloadClaimAttachment $download,
    ): HttpResponse {
        abort_unless((int) $order->workspace_id === TenantContext::id(), 404);
        abort_unless(
            (int) $claim->workspace_id === (int) $order->workspace_id
            && (int) $claim->order_id === (int) $order->id,
            404,
        );

        try {
            $file = $download->execute($claim, $filename);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'no encontrado') || str_contains($message, 'inválido')) {
                abort(404, $message);
            }

            return response($message, 502, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $isImage = str_starts_with($file['content_type'], 'image/');
        $disposition = $isImage ? 'inline' : 'attachment';
        $safeName = str_replace(['"', "\r", "\n"], '', $file['original_filename']);

        return response($file['body'], 200, [
            'Content-Type' => $file['content_type'],
            'Content-Disposition' => $disposition.'; filename="'.$safeName.'"',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return Builder<Order>
     */
    private function baseOrdersQuery(int $workspaceId): Builder
    {
        return Order::query()
            ->where('workspace_id', $workspaceId)
            ->with([
                'connection:id,provider,external_user_id,display_name,color',
                'pack:id,external_pack_id',
                'profitSnapshots' => fn ($q) => $q
                    ->where('stage', 'expected')
                    ->orderByDesc('id'),
                'lines' => fn ($q) => $q
                    ->orderBy('id')
                    ->select(['id', 'order_id', 'title', 'sku', 'external_item_id', 'variant_id']),
                'lines.variant:id,product_id',
            ])
            ->withCount('lines')
            ->withCount([
                'messages as inbound_messages_count' => fn ($q) => $q->where('direction', 'inbound'),
                'financialEvents as ads_attribution_count' => fn ($q) => $q
                    ->where('stage', 'expected')
                    ->where('event_type', AttributeAdvertisingToOrders::EVENT_ALLOCATED),
            ])
            ->withSum([
                'financialEvents as ads_allocated_amount_raw' => fn ($q) => $q
                    ->where('stage', 'expected')
                    ->where('event_type', AttributeAdvertisingToOrders::EVENT_ALLOCATED),
            ], 'amount');
    }

    /**
     * @param  Collection<int, Order>  $orders
     */
    private function enrichProductSalesCounts(int $workspaceId, Collection $orders): void
    {
        $productIds = [];
        $mlItemIds = [];
        foreach ($orders as $order) {
            $summary = $order->getAttribute('product_summary');
            if (! is_array($summary)) {
                continue;
            }
            if (! empty($summary['product_id'])) {
                $productIds[] = (int) $summary['product_id'];
            }
            if (! empty($summary['ml_item_id']) && is_string($summary['ml_item_id'])) {
                $mlItemIds[] = $summary['ml_item_id'];
            }
        }

        if ($productIds === [] && $mlItemIds === []) {
            return;
        }

        $counts = app(ProductSalesSummaryService::class)->unitsSoldByKeys(
            $workspaceId,
            $productIds,
            $mlItemIds,
        );

        foreach ($orders as $order) {
            $summary = $order->getAttribute('product_summary');
            if (! is_array($summary)) {
                continue;
            }

            $units = null;
            $productId = isset($summary['product_id']) ? (int) $summary['product_id'] : null;
            if ($productId !== null && $productId > 0) {
                $units = (int) ($counts['by_product'][$productId] ?? 0);
            } elseif (! empty($summary['ml_item_id']) && is_string($summary['ml_item_id'])) {
                $units = (int) ($counts['by_ml_item'][$summary['ml_item_id']] ?? 0);
            }

            $summary['units_sold'] = $units;
            $order->setAttribute('product_summary', $summary);
        }
    }

    private function transformOrderListRow(Order $order): Order
    {
        $provider = $order->connection?->provider;
        $order->setAttribute(
            'platform',
            match ($provider) {
                'mercadolibre' => 'Mercado Libre',
                default => $provider,
            },
        );
        $metaBuyer = is_array($order->meta['buyer'] ?? null) ? $order->meta['buyer'] : null;
        $order->setAttribute(
            'buyer_summary',
            BuyerPresentation::summary(
                $order->buyer_external_id,
                $metaBuyer,
            ),
        );
        $order->setAttribute(
            'buyer_display',
            BuyerPresentation::summaryParts(
                $order->buyer_external_id,
                $metaBuyer,
            ),
        );

        $primaryLine = $order->lines->first();
        $linesCount = (int) ($order->lines_count ?? $order->lines->count());
        if ($primaryLine !== null) {
            $mlItemId = is_string($primaryLine->external_item_id) && $primaryLine->external_item_id !== ''
                ? $primaryLine->external_item_id
                : null;
            $productId = $primaryLine->variant?->product_id;
            $order->setAttribute('product_summary', [
                'title' => $primaryLine->title,
                'sku' => $primaryLine->sku,
                'product_id' => $productId !== null ? (int) $productId : null,
                'ml_item_id' => $mlItemId,
                'more_count' => max(0, $linesCount - 1),
                'units_sold' => null,
            ]);
        } else {
            $order->setAttribute('product_summary', null);
        }
        $order->unsetRelation('lines');

        $snapshot = $order->profitSnapshots->first();
        $payload = is_array($snapshot?->payload) ? $snapshot->payload : [];
        $breakdown = is_array($payload['breakdown'] ?? null) ? $payload['breakdown'] : [];

        $revenue = $snapshot !== null ? (float) $snapshot->revenue_amount : 0.0;
        $fees = $snapshot !== null ? (float) $snapshot->fees_amount : 0.0;
        $cogs = $snapshot !== null ? (float) $snapshot->cogs_amount : 0.0;
        $profit = $snapshot !== null ? (float) $snapshot->profit_amount : 0.0;

        $taxes = (float) ($payload['taxes_retention_total']
            ?? $breakdown['taxes_retention_total']
            ?? 0);
        $marketplaceNetRaw = $payload['net_received_amount']
            ?? $payload['marketplace_net_amount']
            ?? $breakdown['marketplace_net']
            ?? null;
        $marketplaceNet = $marketplaceNetRaw !== null && $marketplaceNetRaw !== ''
            ? (float) $marketplaceNetRaw
            : ($revenue - $fees - $taxes);

        $order->setAttribute('profit_revenue', $revenue);
        $order->setAttribute('profit_fees', $fees);
        $order->setAttribute('profit_taxes', $taxes);
        $order->setAttribute('profit_marketplace_net', $marketplaceNet);
        $order->setAttribute('profit_cogs', $cogs);
        $order->setAttribute('profit_amount', $profit);
        $order->setAttribute('profit_incomplete', (bool) ($snapshot?->is_incomplete ?? false));
        $order->unsetRelation('profitSnapshots');

        $adsCount = (int) ($order->getAttribute('ads_attribution_count') ?? 0);
        $adsRaw = (float) ($order->getAttribute('ads_allocated_amount_raw') ?? 0);
        $order->setAttribute('has_ads_attribution', $adsCount > 0);
        $order->setAttribute('ads_allocated_amount', $adsCount > 0 ? round(abs($adsRaw), 2) : 0.0);

        return $order;
    }

    /**
     * @param  Collection<int, Order>  $orders
     */
    private function enrichReleaseIssue(Collection $orders, ?string $releaseIssue): void
    {
        if ($releaseIssue === null || $releaseIssue === '' || $orders->isEmpty()) {
            return;
        }

        $orders->load([
            'marketplacePayments:id,order_id,status,is_released,net_received_amount,expected_net_amount,money_release_at',
            'shipments' => fn ($q) => $q
                ->where('status', 'delivered')
                ->whereNotNull('delivered_at')
                ->orderByDesc('delivered_at'),
        ]);

        $detector = app(OverdueReleaseQuery::class);
        foreach ($orders as $order) {
            $expected = $detector->expectedReleaseAt($order);
            $order->setAttribute('release_issue', $detector->classify($order));
            $order->setAttribute('release_delivered_at', $detector->deliveredAt($order)?->toIso8601String());
            $order->setAttribute('expected_release_at', $expected['at']?->toIso8601String());
            $order->setAttribute('expected_release_source', $expected['source']);
        }
    }

    /**
     * Marca órdenes “repartidas”:
     * 1) mismo pack_id de ML (count > 1)
     * 2) si no, mismo canal + comprador + minuto de compra (burst; ML a veces da pack distinto por ítem)
     *
     * @param  Collection<int, Order>  $orders
     */
    private function enrichPackGrouping(Collection $orders): void
    {
        if ($orders->isEmpty()) {
            return;
        }

        $packIds = $orders->pluck('pack_id')->filter()->unique()->values();
        /** @var Collection<int|string, int> $packCounts */
        $packCounts = $packIds->isEmpty()
            ? collect()
            : Order::query()
                ->whereIn('pack_id', $packIds)
                ->selectRaw('pack_id, COUNT(*) as aggregate')
                ->groupBy('pack_id')
                ->pluck('aggregate', 'pack_id');

        /** @var array<string, array{connection_id:int, buyer:string, from:string, to:string}> $burstDefs */
        $burstDefs = [];
        foreach ($orders as $order) {
            $packId = $order->pack_id;
            $packCount = $packId !== null ? (int) ($packCounts[$packId] ?? 1) : 1;
            $external = $order->pack?->external_pack_id;
            if ($packCount > 1 && is_string($external) && $external !== '') {
                $order->setAttribute('pack_external_id', $external);
                $order->setAttribute('pack_orders_count', $packCount);
                $order->setAttribute('pack_group_kind', 'pack');
                $order->makeHidden(['pack']);

                continue;
            }

            $burstKey = $this->softPurchaseGroupKey($order);
            if ($burstKey === null) {
                $order->setAttribute('pack_external_id', null);
                $order->setAttribute('pack_orders_count', 1);
                $order->setAttribute('pack_group_kind', null);
                $order->makeHidden(['pack']);

                continue;
            }

            if (! isset($burstDefs[$burstKey])) {
                $orderedAt = $order->ordered_at;
                $burstDefs[$burstKey] = [
                    'connection_id' => (int) $order->connection_id,
                    'buyer' => (string) $order->buyer_external_id,
                    'from' => $orderedAt->copy()->startOfMinute()->toDateTimeString(),
                    'to' => $orderedAt->copy()->endOfMinute()->toDateTimeString(),
                ];
            }

            $order->setAttribute('_burst_key', $burstKey);
            $order->makeHidden(['pack']);
        }

        /** @var array<string, int> $burstCounts */
        $burstCounts = [];
        $workspaceId = (int) $orders->first()->workspace_id;
        foreach ($burstDefs as $key => $def) {
            $burstCounts[$key] = (int) Order::query()
                ->where('workspace_id', $workspaceId)
                ->where('connection_id', $def['connection_id'])
                ->where('buyer_external_id', $def['buyer'])
                ->whereBetween('ordered_at', [$def['from'], $def['to']])
                ->count();
        }

        foreach ($orders as $order) {
            if ($order->getAttribute('pack_group_kind') === 'pack') {
                continue;
            }

            $burstKey = $order->getAttribute('_burst_key');
            $order->offsetUnset('_burst_key');
            $count = is_string($burstKey) ? (int) ($burstCounts[$burstKey] ?? 1) : 1;

            if ($count > 1 && is_string($burstKey)) {
                $order->setAttribute('pack_external_id', $burstKey);
                $order->setAttribute('pack_orders_count', $count);
                $order->setAttribute('pack_group_kind', 'burst');
            } else {
                $order->setAttribute('pack_external_id', null);
                $order->setAttribute('pack_orders_count', 1);
                $order->setAttribute('pack_group_kind', null);
            }
        }
    }

    private function softPurchaseGroupKey(Order $order): ?string
    {
        $buyer = $order->buyer_external_id;
        $orderedAt = $order->ordered_at;
        if ($buyer === null || $buyer === '' || $orderedAt === null) {
            return null;
        }

        return sprintf(
            '%d:%s:%s',
            (int) $order->connection_id,
            (string) $buyer,
            $orderedAt->format('Y-m-d\TH:i'),
        );
    }

    /**
     * @return array{order: Order, shipment: Shipment|null, timeline: list<array<string, mixed>>}
     */
    private function buildShowPayload(Order $order): array
    {
        $refresher = app(RefreshExpectedOrderFinance::class);
        if ($refresher->needsRefresh($order)) {
            $refresher->execute($order);
            $order->refresh();
        }

        $order->load([
            'lines' => fn ($q) => $q->orderBy('id'),
            'lines.variant:id,product_id',
            'profitSnapshots',
            'connection:id,provider,external_user_id,display_name,color',
            'financialEvents' => fn ($q) => $q->where('stage', 'expected')->orderBy('id'),
        ]);

        foreach ($order->lines as $line) {
            $productId = $line->variant?->product_id;
            $line->setAttribute('product_id', $productId !== null ? (int) $productId : null);
            $line->unsetRelation('variant');
        }

        $shipment = Shipment::query()
            ->where('workspace_id', $order->workspace_id)
            ->where(function ($query) use ($order) {
                $query->where('order_id', $order->id);
                $shippingId = $order->meta['shipping']['id'] ?? null;
                if ($shippingId !== null && $shippingId !== '') {
                    $query->orWhere('external_shipment_id', (string) $shippingId);
                }
            })
            ->first();

        $buyerInboundCount = OrderMessage::query()
            ->where('order_id', $order->id)
            ->where('direction', 'inbound')
            ->count();

        $claims = Claim::query()
            ->where('workspace_id', $order->workspace_id)
            ->where('order_id', $order->id)
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->get();

        $serializedClaims = $claims->map(fn (Claim $claim) => $this->serializeClaim($claim))->values();
        $openedClaimsCount = $claims->where('status', 'opened')->count();

        $stockEvidence = app(ResolveOrderStockEvidence::class)->execute($order);

        $timeline = [
            [
                'key' => 'ordered',
                'label' => 'Creada',
                'at' => $order->ordered_at,
                'done' => $order->ordered_at !== null,
            ],
            [
                'key' => 'paid',
                'label' => 'Pagada',
                'at' => $order->paid_at,
                'done' => $order->paid_at !== null || in_array($order->status, ['paid', 'shipped', 'delivered'], true),
            ],
            [
                'key' => 'reserved',
                'label' => 'Stock reservado',
                'at' => $stockEvidence['at'],
                'done' => $stockEvidence['done'],
            ],
            [
                'key' => 'shipped',
                'label' => 'Enviada',
                'at' => $shipment?->shipped_at,
                'done' => $shipment !== null && in_array($shipment->status, ['shipped', 'delivered', 'in_transit'], true),
            ],
            [
                'key' => 'delivered',
                'label' => 'Entregada',
                'at' => $shipment?->delivered_at,
                'done' => ($shipment?->status === 'delivered') || $order->status === 'delivered',
            ],
        ];

        $outcome = $order->post_sale_outcome;
        if (in_array($outcome, ['returned', 'refunded'], true)) {
            $closingClaim = $claims->first(function (Claim $claim) use ($outcome) {
                if ($claim->status !== 'closed') {
                    return false;
                }
                $reason = $claim->resolutionReason();

                return ($outcome === 'returned' && $reason === 'item_returned')
                    || ($outcome === 'refunded' && $reason === 'payment_refunded');
            }) ?? $claims->firstWhere('status', 'closed');

            $timeline[] = [
                'key' => $outcome === 'returned' ? 'returned' : 'refunded',
                'label' => $outcome === 'returned' ? 'Devuelta' : 'Reembolsada',
                'at' => $closingClaim?->closed_at,
                'done' => true,
            ];
        }

        if ($outcome === ResolveOrderPostSaleOutcome::RETURNED) {
            app(RestockOrderFromReturn::class)->execute($order->fresh(['lines']) ?? $order);
            $order->load('lines');
        }

        $postSale = app(BuildOrderPostSaleSummary::class)->execute($order, $claims, $shipment);

        return [
            'order' => $order,
            'shipment' => $shipment,
            'timeline' => $timeline,
            'message_stats' => [
                'buyer_inbound_count' => $buyerInboundCount,
                'total_count' => OrderMessage::query()->where('order_id', $order->id)->count(),
            ],
            'claims' => $serializedClaims,
            'claim_stats' => [
                'opened_count' => $openedClaimsCount,
                'total_count' => $claims->count(),
            ],
            'post_sale' => $postSale,
            'ads_attribution' => $this->buildAdsAttributionSummary($order),
            'invoice_probe' => $this->invoiceProbe($order),
        ];
    }

    /**
     * @return array{status: string, source: string|null, uuid: string|null, folio: string|null, fetched_at: string|null}
     */
    private function invoiceProbe(Order $order): array
    {
        $meta = is_array($order->meta) ? $order->meta : [];
        $cached = is_array($meta['sat_invoice'] ?? null) ? $meta['sat_invoice'] : null;
        if ($cached === null) {
            return [
                'status' => 'unknown',
                'source' => null,
                'uuid' => null,
                'folio' => null,
                'fetched_at' => null,
            ];
        }

        return [
            'status' => is_string($cached['status'] ?? null) ? $cached['status'] : 'unknown',
            'source' => is_string($cached['source'] ?? null) ? $cached['source'] : null,
            'uuid' => is_string($cached['uuid'] ?? null) ? $cached['uuid'] : null,
            'folio' => is_string($cached['folio'] ?? null) ? $cached['folio'] : null,
            'fetched_at' => is_string($cached['fetched_at'] ?? null) ? $cached['fetched_at'] : null,
        ];
    }

    private function invoiceBinary(Order $order, FetchOrderSatInvoice $fetch, string $kind): HttpResponse
    {
        $payload = $fetch->execute($order, includeFiles: true);
        if ($payload['status'] === FetchOrderSatInvoice::STATUS_FORBIDDEN) {
            abort(403, $payload['error'] ?? 'Sin permiso de Facturación.');
        }
        if ($payload['status'] !== FetchOrderSatInvoice::STATUS_FOUND) {
            abort(404, $payload['error'] ?? 'No hay factura SAT para esta orden.');
        }

        $base64 = $kind === 'xml' ? $payload['xml_base64'] : $payload['pdf_base64'];
        $binary = SatInvoiceFileCodec::decode(is_string($base64) ? $base64 : null);
        if ($binary === null || $binary === '') {
            abort(404, $kind === 'xml' ? 'No hay XML de la factura.' : 'No hay PDF de la factura.');
        }

        $orderNumber = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($order->external_order_id ?: $order->id)) ?: (string) $order->id;
        if ($kind === 'xml') {
            return response($binary, 200, [
                'Content-Type' => 'application/xml; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="factura-'.$orderNumber.'.xml"',
                'Cache-Control' => 'private, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="factura-'.$orderNumber.'.pdf"',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array{
     *     has_ads: bool,
     *     total_amount: float,
     *     currency_code: string,
     *     events: list<array<string, mixed>>,
     *     day_summary: array<string, mixed>|null,
     *     unattributed_days: list<array<string, mixed>>
     * }
     */
    private function buildAdsAttributionSummary(Order $order): array
    {
        $events = $order->financialEvents
            ->filter(fn ($e) => $e->stage === 'expected'
                && $e->event_type === AttributeAdvertisingToOrders::EVENT_ALLOCATED)
            ->values();

        $currency = (string) ($order->currency_code ?: 'MXN');
        $total = 0.0;
        $rows = [];

        foreach ($events as $event) {
            $amount = abs((float) ($event->reporting_amount ?? $event->amount));
            $total += $amount;
            $prov = is_array($event->provenance) ? $event->provenance : [];
            if (! empty($event->currency_code)) {
                $currency = (string) $event->currency_code;
            } elseif (! empty($event->reporting_currency)) {
                $currency = (string) $event->reporting_currency;
            }

            $rows[] = [
                'order_line_id' => $event->order_line_id,
                'amount' => round($amount, 2),
                'ml_item_id' => $prov['ml_item_id'] ?? null,
                'date' => $prov['date'] ?? null,
                'rate' => isset($prov['rate']) ? (float) $prov['rate'] : null,
                'ads_cost' => isset($prov['ads_cost']) ? (float) $prov['ads_cost'] : null,
                'ml_revenue' => isset($prov['ml_revenue']) ? (float) $prov['ml_revenue'] : null,
                'item_gmv' => isset($prov['item_gmv']) ? (float) $prov['item_gmv'] : null,
                'coverage_ratio' => isset($prov['coverage_ratio']) ? (float) $prov['coverage_ratio'] : null,
                'denom' => isset($prov['denom']) ? (float) $prov['denom'] : null,
                'residual' => isset($prov['residual']) ? (float) $prov['residual'] : null,
                'model' => $prov['model'] ?? null,
                'source' => $prov['source'] ?? null,
                'note' => $prov['note'] ?? null,
            ];
        }

        // One representative provenance per item+day (avoid summing day metrics across lines).
        $daySummary = null;
        if ($rows !== []) {
            $first = $rows[0];
            $daySummary = [
                'ml_item_id' => $first['ml_item_id'],
                'date' => $first['date'],
                'ads_cost' => $first['ads_cost'],
                'ml_revenue' => $first['ml_revenue'],
                'item_gmv' => $first['item_gmv'],
                'denom' => $first['denom'],
                'residual' => $first['residual'],
                'coverage_ratio' => $first['coverage_ratio'],
                'rate' => $first['rate'],
                'allocated_to_order' => round($total, 2),
                'note' => $first['note'],
            ];
        }

        $unattributedDays = $this->buildUnattributedAdsDays($order);

        return [
            'has_ads' => $rows !== [],
            'total_amount' => round($total, 2),
            'currency_code' => $currency,
            'events' => $rows,
            'day_summary' => $daySummary,
            'unattributed_days' => $unattributedDays,
            'has_unattributed_spend' => $unattributedDays !== [],
        ];
    }

    /**
     * Spend on the order's items+day with ML attributed revenue = 0 (waste / organic).
     *
     * @return list<array<string, mixed>>
     */
    private function buildUnattributedAdsDays(Order $order): array
    {
        if ($order->ordered_at === null) {
            return [];
        }

        $order->loadMissing('lines');
        $date = $order->ordered_at->copy()->timezone(BusinessDay::timezone())->toDateString();
        $itemIds = $order->lines
            ->pluck('external_item_id')
            ->filter(fn ($id) => is_string($id) && trim($id) !== '')
            ->map(fn ($id) => trim((string) $id))
            ->unique()
            ->values()
            ->all();

        if ($itemIds === []) {
            return [];
        }

        $spendRows = AdSpendDaily::query()
            ->where('workspace_id', $order->workspace_id)
            ->where('connection_id', $order->connection_id)
            ->whereDate('date', $date)
            ->whereIn('ml_item_id', $itemIds)
            ->selectRaw('
                ml_item_id,
                SUM(cost) as cost,
                SUM(COALESCE(total_amount, 0)) as ml_revenue,
                SUM(COALESCE(organic_units_amount, 0)) as organic_amount,
                SUM(COALESCE(organic_units_quantity, 0)) as organic_units
            ')
            ->groupBy('ml_item_id')
            ->get();

        $out = [];
        foreach ($spendRows as $row) {
            $cost = (float) $row->cost;
            $mlRevenue = (float) $row->ml_revenue;
            if ($cost <= 0 || $mlRevenue > 0) {
                continue;
            }

            $residual = (float) FinancialEvent::query()
                ->where('workspace_id', $order->workspace_id)
                ->where('connection_id', $order->connection_id)
                ->where('event_type', AttributeAdvertisingToOrders::EVENT_UNALLOCATED)
                ->where('stage', 'expected')
                ->whereNull('order_id')
                ->where('provenance->ml_item_id', (string) $row->ml_item_id)
                ->where('provenance->date', $date)
                ->sum(DB::raw('ABS(amount)'));

            $out[] = [
                'ml_item_id' => (string) $row->ml_item_id,
                'date' => $date,
                'ads_cost' => round($cost, 2),
                'ml_revenue' => 0.0,
                'residual' => round($residual > 0 ? $residual : $cost, 2),
                'organic_amount' => round((float) $row->organic_amount, 2),
                'organic_units' => round((float) $row->organic_units, 2),
                'note' => 'Hubo gasto ads ese día, pero ML no atribuyó ventas (posible orgánico / búsqueda directa). El gasto queda como overhead, no en el P&L de esta orden.',
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function serializeOrderFullOperation(FullStockOperation $op): array
    {
        return [
            'id' => $op->id,
            'operation_type' => $op->operation_type,
            'operation_type_label' => FullStockOperationType::label((string) $op->operation_type),
            'occurred_at' => optional($op->occurred_at)?->toIso8601String(),
            'inventory_id' => $op->inventory_id,
            'available_quantity_delta' => $op->available_quantity_delta !== null
                ? (string) $op->available_quantity_delta
                : null,
            'not_available_quantity_delta' => $op->not_available_quantity_delta !== null
                ? (string) $op->not_available_quantity_delta
                : null,
            'result_available' => $op->result_available !== null
                ? (string) $op->result_available
                : null,
            'result_not_available' => $op->result_not_available !== null
                ? (string) $op->result_not_available
                : null,
            'variant_id' => $op->variant_id,
            'variant_sku' => $op->variant?->sku,
            'external_references' => $op->external_references ?? [],
        ];
    }

    private function serializeClaim(Claim $claim): array
    {
        return [
            'id' => $claim->id,
            'external_claim_id' => $claim->external_claim_id,
            'type' => $claim->type,
            'stage' => $claim->stage,
            'status' => $claim->status,
            'reason' => $claim->reason,
            'reason_id' => $claim->reason_id,
            'reason_detail' => $claim->reason_detail,
            'problem' => $claim->problem,
            'status_title' => $claim->status_title,
            'status_description' => $claim->status_description,
            'due_at' => $claim->due_at,
            'affects_reputation' => $claim->affects_reputation,
            'has_incentive' => $claim->has_incentive,
            'reputation_due_at' => $claim->reputation_due_at,
            'resource' => $claim->resource,
            'resource_external_id' => $claim->resource_external_id,
            'opened_at' => $claim->opened_at,
            'closed_at' => $claim->closed_at,
            'resolution_reason' => $claim->resolutionReason(),
            'meta' => $claim->meta,
            'can_message_mediator' => $claim->canMessageMediator(),
        ];
    }
}
