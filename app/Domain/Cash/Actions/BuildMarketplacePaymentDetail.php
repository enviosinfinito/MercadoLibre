<?php

namespace App\Domain\Cash\Actions;

use App\Domain\Cash\Support\CashMoney;
use App\Domain\Cash\Support\CashMovementConcept;
use App\Models\CashLedgerEntry;
use App\Models\CashReconciliationLink;
use App\Models\MarketplacePayment;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Payment detail payload: cash-out trail (Meli → seller) + optional release cohort.
 *
 * @return array{
 *   kind: string,
 *   payment: MarketplacePayment,
 *   diff: array<string, mixed>|null,
 *   aligned: array{shipping: string|null, tax: string|null, fee: string|null, net: string|null, revenue: string|null},
 *   cashout_trail: array<string, mixed>,
 *   ledger_entries: list<array<string, mixed>>,
 *   release_batch: array<string, mixed>
 * }
 */
final class BuildMarketplacePaymentDetail
{
    private const BATCH_LIMIT = 200;

    public function __construct(
        private readonly BuildOrderCashDiff $orderCashDiff,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(MarketplacePayment $payment): array
    {
        $payment->loadMissing([
            'order:id,external_order_id,status,total_amount,currency_code',
            'connection:id,display_name',
        ]);

        $diff = null;
        if ($payment->order_id) {
            $order = Order::query()->find($payment->order_id);
            if ($order !== null) {
                $diff = $this->orderCashDiff->execute($order);
            }
        }

        $ledgerEntries = $this->ledgerForPayment($payment);
        $releaseBatch = $this->releaseBatch($payment);

        return [
            'kind' => 'payment',
            'payment' => $payment,
            'diff' => $diff,
            'aligned' => $this->alignedFromDiff($diff, $payment),
            'cashout_trail' => $this->cashoutTrail($payment, $diff),
            'ledger_entries' => $ledgerEntries,
            'release_batch' => $releaseBatch,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $diff
     * @return array{
     *   collection: array<string, mixed>,
     *   mp_release: array<string, mixed>|null,
     *   bank_withdrawal: array<string, mixed>|null
     * }
     */
    private function cashoutTrail(MarketplacePayment $payment, ?array $diff): array
    {
        $expectedNet = null;
        $orderPaymentCount = count($diff['payments'] ?? []);
        if ($orderPaymentCount <= 1) {
            foreach ($diff['rows'] ?? [] as $row) {
                if (($row['concept'] ?? null) === 'Neto cobrado' && isset($row['expected'])) {
                    $expectedNet = CashMoney::scale((string) $row['expected']);
                    break;
                }
            }
        }
        $expectedNet ??= CashMoney::scale((string) ($payment->net_received_amount ?? $payment->expected_net_amount ?? ''));

        $collectionNet = CashMoney::scale((string) ($payment->net_received_amount ?? ''));
        $collectionDiff = ($expectedNet !== null && $collectionNet !== null)
            ? CashMoney::sub($expectedNet, $collectionNet)
            : null;

        $collection = [
            'external_payment_id' => $payment->external_payment_id,
            'status' => $payment->status,
            'status_detail' => $payment->status_detail,
            'net_amount' => $collectionNet,
            'expected_net' => $expectedNet,
            'diff' => $collectionDiff,
            'status_recon' => $collectionDiff !== null
                ? CashMoney::statusFromDiff($collectionDiff)
                : ($payment->reconciliation_status ?? 'pending'),
            'paid_at' => $payment->paid_at?->toIso8601String(),
        ];

        return [
            'collection' => $collection,
            'mp_release' => $this->mpReleaseStep($payment, $expectedNet),
            'bank_withdrawal' => $this->bankWithdrawalStep($payment, $expectedNet),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mpReleaseStep(MarketplacePayment $payment, ?string $expectedNet): ?array
    {
        $link = CashReconciliationLink::query()
            ->where('marketplace_payment_id', $payment->id)
            ->whereIn('match_method', ['source_id', 'order_id'])
            ->whereHas('ledgerEntry', fn ($q) => $q->whereIn('entry_type', ['settlement', 'release']))
            ->with('ledgerEntry')
            ->orderByDesc('id')
            ->first();

        $entry = $link?->ledgerEntry;
        if ($entry === null) {
            $entry = CashLedgerEntry::query()
                ->where('connection_id', $payment->connection_id)
                ->where('external_source_id', $payment->external_payment_id)
                ->whereIn('entry_type', ['settlement', 'release'])
                ->orderByRaw("CASE WHEN UPPER(COALESCE(transaction_type, '')) IN ('PAYMENT', 'SETTLEMENT') THEN 0 ELSE 1 END")
                ->orderByDesc('id')
                ->first();
        } elseif (! in_array(strtoupper((string) $entry->transaction_type), ['PAYMENT', 'SETTLEMENT'], true)) {
            $saleEntry = CashLedgerEntry::query()
                ->where('connection_id', $payment->connection_id)
                ->where('external_source_id', $payment->external_payment_id)
                ->whereIn('entry_type', ['settlement', 'release'])
                ->whereIn('transaction_type', ['PAYMENT', 'SETTLEMENT'])
                ->orderByDesc('id')
                ->first();
            if ($saleEntry !== null) {
                $entry = $saleEntry;
            }
        }

        $movements = $this->releaseMovementsForPayment($payment);
        if ($entry === null && $movements === []) {
            return null;
        }

        $net = $entry !== null ? CashMoney::scale((string) $entry->net_amount) : null;
        $totalReleased = CashMoney::zero();
        $hasMovementNet = false;
        $hasDispute = false;
        foreach ($movements as $movement) {
            if (($movement['concept'] ?? null) === CashMovementConcept::DISPUTE) {
                $hasDispute = true;
            }
            if ($movement['net_amount'] === null) {
                continue;
            }
            $hasMovementNet = true;
            $totalReleased = CashMoney::add($totalReleased, (string) $movement['net_amount']);
        }
        if (! $hasMovementNet && $net !== null) {
            $totalReleased = $net;
        }

        $diff = ($expectedNet !== null && $net !== null)
            ? CashMoney::sub($expectedNet, $net)
            : ($link?->diff_amount !== null ? CashMoney::scale((string) $link->diff_amount) : null);
        $status = $diff !== null
            ? CashMoney::statusFromDiff($diff)
            : (string) ($link?->status ?? 'matched');
        if ($hasDispute || $payment->status === 'in_mediation') {
            $status = 'reserved';
        }

        $primary = $entry ?? null;

        return [
            'ledger_entry_id' => $primary?->id,
            'external_source_id' => $primary?->external_source_id,
            'entry_type' => $primary?->entry_type,
            'net_amount' => $net,
            'total_released' => $totalReleased,
            'is_released' => $primary?->is_released ?? $payment->is_released,
            'released_at' => ($primary?->released_at ?? $payment->money_release_at)?->toIso8601String(),
            'occurred_at' => $primary?->occurred_at?->toIso8601String(),
            'expected_net' => $expectedNet,
            'diff' => $diff,
            'status' => $status,
            'match_method' => $link?->match_method ?? 'source_id',
            'has_dispute' => $hasDispute || $payment->status === 'in_mediation',
            'movements' => $movements,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function releaseMovementsForPayment(MarketplacePayment $payment): array
    {
        $orderExternalId = $payment->order?->external_order_id;
        $shippingIds = [];
        if (is_string($orderExternalId) && $orderExternalId !== '') {
            $shippingIds = CashLedgerEntry::query()
                ->where('connection_id', $payment->connection_id)
                ->where(function ($q) use ($orderExternalId) {
                    $q->where('external_order_id', $orderExternalId)
                        ->orWhere('external_reference', $orderExternalId);
                })
                ->where(function ($q) {
                    $q->whereIn('transaction_type', ['SHIPPING', 'SETTLEMENT_SHIPPING'])
                        ->orWhere('transaction_type', 'like', '%SHIPPING%')
                        ->orWhereNotNull('external_shipping_id');
                })
                ->limit(40)
                ->get(['external_shipping_id', 'external_reference', 'external_source_id'])
                ->flatMap(fn (CashLedgerEntry $e) => array_filter([
                    $e->external_shipping_id,
                    $e->external_reference,
                    $e->external_source_id,
                ]))
                ->filter(fn ($id) => is_string($id) && CashMovementConcept::looksLikeShippingId($id))
                ->unique()
                ->values()
                ->all();
        }

        $entries = CashLedgerEntry::query()
            ->where('connection_id', $payment->connection_id)
            ->whereIn('entry_type', ['settlement', 'release'])
            ->where(function ($q) use ($payment, $orderExternalId, $shippingIds) {
                $q->where('external_source_id', $payment->external_payment_id);
                if (is_string($orderExternalId) && $orderExternalId !== '') {
                    $q->orWhere('external_order_id', $orderExternalId);
                }
                if ($shippingIds !== []) {
                    $q->orWhereIn('external_order_id', $shippingIds)
                        ->orWhereIn('external_shipping_id', $shippingIds)
                        ->orWhereIn('external_reference', $shippingIds)
                        ->orWhereIn('external_source_id', $shippingIds);
                }
            })
            ->orderBy('id')
            ->limit(40)
            ->get();

        $seen = [];
        $movements = [];
        foreach ($entries as $entry) {
            $lookup = (string) ($entry->external_order_id ?: $entry->external_reference ?: '');
            $concept = CashMovementConcept::describe(
                $entry->transaction_type,
                $entry->entry_type,
                $lookup,
                $entry->net_amount !== null ? (string) $entry->net_amount : null,
            );
            $key = ((string) ($entry->external_source_id ?: 'id:'.$entry->id)).'|'.$concept['key'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $movements[] = [
                'concept' => $concept['key'],
                'concept_label' => $concept['label'],
                'concept_hint' => $concept['hint'],
                'transaction_type' => $entry->transaction_type,
                'entry_type' => $entry->entry_type,
                'ledger_entry_id' => $entry->id,
                'external_source_id' => $entry->external_source_id,
                'external_order_id' => $entry->external_order_id,
                'net_amount' => CashMoney::scale((string) $entry->net_amount),
                'is_released' => $entry->is_released,
                'released_at' => ($entry->released_at ?? $entry->occurred_at)?->toIso8601String(),
                'provenance' => $entry->provenance,
            ];
        }

        return $movements;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function bankWithdrawalStep(MarketplacePayment $payment, ?string $expectedNet): ?array
    {
        $link = CashReconciliationLink::query()
            ->where('marketplace_payment_id', $payment->id)
            ->where('match_method', 'withdrawal_fifo')
            ->with('ledgerEntry')
            ->orderByDesc('id')
            ->first();

        if ($link === null || $link->ledgerEntry === null) {
            return null;
        }

        $entry = $link->ledgerEntry;
        $allocated = CashMoney::scale((string) $link->allocated_amount);
        $expected = CashMoney::scale((string) ($link->expected_amount ?? $expectedNet ?? ''));
        $diff = $link->diff_amount !== null
            ? CashMoney::scale((string) $link->diff_amount)
            : (($expected !== null && $allocated !== null) ? CashMoney::sub($expected, $allocated) : null);

        return [
            'ledger_entry_id' => $entry->id,
            'external_source_id' => $entry->external_source_id,
            'withdrawal_net_total' => CashMoney::abs(CashMoney::scale((string) $entry->net_amount) ?? '0'),
            'allocated_amount' => $allocated,
            'expected_net' => $expected,
            'diff' => $diff,
            'status' => $diff !== null ? CashMoney::statusFromDiff($diff) : (string) $link->status,
            'occurred_at' => $entry->occurred_at?->toIso8601String(),
            'match_method' => 'withdrawal_fifo',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $diff
     * @return array{shipping: string|null, tax: string|null, fee: string|null, net: string|null, revenue: string|null}
     */
    private function alignedFromDiff(?array $diff, MarketplacePayment $payment): array
    {
        $byConcept = collect($diff['rows'] ?? [])->keyBy('concept');

        return [
            'revenue' => $byConcept->get('Ingreso')['actual']
                ?? CashMoney::scale((string) ($payment->transaction_amount ?? '')),
            'fee' => $byConcept->get('Comisión')['actual']
                ?? CashMoney::scale((string) ($payment->marketplace_fee_amount ?? '')),
            'shipping' => $byConcept->get('Envío (seller)')['actual']
                ?? CashMoney::scale((string) ($payment->shipping_cost_amount ?? '')),
            'tax' => $byConcept->get('Impuestos / retención')['actual']
                ?? CashMoney::scale((string) ($payment->tax_amount ?? '')),
            'net' => $byConcept->get('Neto cobrado')['actual']
                ?? CashMoney::scale((string) ($payment->net_received_amount ?? '')),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ledgerForPayment(MarketplacePayment $payment): array
    {
        $externalId = (string) $payment->external_payment_id;

        $entries = CashLedgerEntry::query()
            ->where('connection_id', $payment->connection_id)
            ->where(function ($q) use ($payment, $externalId) {
                $q->where('external_source_id', $externalId);
                if ($payment->order?->external_order_id) {
                    $q->orWhere('external_order_id', $payment->order->external_order_id);
                }
                $q->orWhereIn('id', CashReconciliationLink::query()
                    ->where('marketplace_payment_id', $payment->id)
                    ->select('cash_ledger_entry_id'));
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return $entries->map(function (CashLedgerEntry $e) {
            $concept = CashMovementConcept::describe(
                $e->transaction_type,
                $e->entry_type,
                $e->external_order_id ?? $e->external_reference,
                $e->net_amount !== null ? (string) $e->net_amount : null,
            );

            return [
                'id' => $e->id,
                'entry_type' => $e->entry_type,
                'transaction_type' => $e->transaction_type,
                'concept' => $concept['key'],
                'concept_label' => $concept['label'],
                'concept_hint' => $concept['hint'],
                'external_source_id' => $e->external_source_id,
                'external_order_id' => $e->external_order_id,
                'net_amount' => $e->net_amount,
                'gross_amount' => $e->gross_amount,
                'fee_amount' => $e->fee_amount,
                'shipping_fee_amount' => $e->shipping_fee_amount,
                'tax_amount' => $e->tax_amount,
                'currency_code' => $e->currency_code,
                'occurred_at' => $e->occurred_at?->toIso8601String(),
                'released_at' => $e->released_at?->toIso8601String(),
                'is_released' => $e->is_released,
                'provenance' => $e->provenance,
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function releaseBatch(MarketplacePayment $payment): array
    {
        $currency = (string) ($payment->currency_code ?? 'MXN');
        $releaseAt = $payment->money_release_at;
        $releaseStatus = $this->releaseStatus($payment);

        if ($releaseAt === null) {
            $row = $this->batchPaymentRow($payment);
            $row['is_current'] = true;

            return [
                'release_at' => null,
                'release_status' => $releaseStatus,
                'currency' => $currency,
                'payments_count' => 1,
                'total_count' => 1,
                'truncated' => false,
                'net_total' => CashMoney::scale((string) ($payment->net_received_amount ?? '0')) ?? CashMoney::zero(),
                'payments' => [$row],
            ];
        }

        $baseQuery = MarketplacePayment::query()
            ->where('connection_id', $payment->connection_id)
            ->whereNotNull('money_release_at')
            ->whereBetween('money_release_at', [
                $releaseAt->copy()->startOfHour(),
                $releaseAt->copy()->endOfHour(),
            ]);

        $totalCount = (clone $baseQuery)->count();
        $netSum = (clone $baseQuery)->sum('net_received_amount');
        $netTotal = CashMoney::scale((string) ($netSum ?? '0')) ?? CashMoney::zero();
        $truncated = $totalCount > self::BATCH_LIMIT;

        /** @var Collection<int, MarketplacePayment> $siblings */
        $siblings = (clone $baseQuery)
            ->with(['order:id,external_order_id,status'])
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $payment->id])
            ->orderByDesc('net_received_amount')
            ->orderByDesc('id')
            ->limit(self::BATCH_LIMIT)
            ->get();

        $rows = [];
        foreach ($siblings as $sibling) {
            $row = $this->batchPaymentRow($sibling);
            $row['is_current'] = (int) $sibling->id === (int) $payment->id;
            $rows[] = $row;
        }

        return [
            'release_at' => $releaseAt instanceof CarbonInterface
                ? $releaseAt->toIso8601String()
                : null,
            'release_status' => $releaseStatus,
            'currency' => $currency,
            'payments_count' => count($rows),
            'total_count' => $totalCount,
            'truncated' => $truncated,
            'net_total' => $netTotal,
            'payments' => $rows,
        ];
    }

    private function releaseStatus(MarketplacePayment $payment): string
    {
        if ($payment->is_released === true) {
            return 'released';
        }

        $releaseAt = $payment->money_release_at;
        if ($releaseAt === null) {
            return 'unknown';
        }

        if ($releaseAt->isFuture()) {
            return 'scheduled';
        }

        return 'pending';
    }

    /**
     * @return array<string, mixed>
     */
    private function batchPaymentRow(MarketplacePayment $payment): array
    {
        return [
            'id' => $payment->id,
            'external_payment_id' => $payment->external_payment_id,
            'order_id' => $payment->order_id,
            'external_order_id' => $payment->order?->external_order_id,
            'net_received_amount' => $payment->net_received_amount,
            'reconciliation_status' => $payment->reconciliation_status,
            'money_release_at' => $payment->money_release_at?->toIso8601String(),
        ];
    }
}
