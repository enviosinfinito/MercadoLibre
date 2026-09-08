<?php

namespace App\Domain\Cash\Actions;

use App\Domain\Cash\Support\CashMoney;
use App\Domain\Cash\Support\CashMovementConcept;
use App\Models\CashLedgerEntry;
use App\Models\Connection;
use App\Models\MarketplacePayment;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Drill-down for one hourly liberación: groups by ML sale (cobro + crédito envío).
 */
final class BuildCashReleaseBucketDetail
{
    private const LIMIT = 300;

    /**
     * @return array<string, mixed>
     */
    public function execute(int $workspaceId, string $bucketId): array
    {
        [$connectionId, $hour] = $this->parseBucketId($bucketId);

        $connection = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->where('id', $connectionId)
            ->firstOrFail();

        $from = $hour;
        $to = $hour->endOfHour();

        $official = CashLedgerEntry::query()
            ->where('workspace_id', $workspaceId)
            ->where('connection_id', $connectionId)
            ->where('entry_type', 'release')
            ->where('provenance', 'mp_release_report')
            ->whereNotNull('occurred_at')
            ->whereBetween('occurred_at', [$from, $to])
            ->orderByDesc('net_amount')
            ->limit(self::LIMIT)
            ->get();

        $source = 'official_release';
        $entries = $official;

        if ($entries->isEmpty()) {
            $source = 'settlement_estimate';
            $entries = CashLedgerEntry::query()
                ->where('workspace_id', $workspaceId)
                ->where('connection_id', $connectionId)
                ->where('entry_type', 'settlement')
                ->whereNotNull('released_at')
                ->whereBetween('released_at', [$from, $to])
                ->orderByDesc('net_amount')
                ->limit(self::LIMIT)
                ->get();
        }

        $shippingMap = $this->resolveShippingOrderMap($workspaceId, $connectionId, $entries);

        $orderExternalIds = $entries
            ->pluck('external_order_id')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->merge(array_values($shippingMap))
            ->unique()
            ->values()
            ->all();

        $sourceIds = $entries
            ->pluck('external_source_id')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        $ordersByExternal = collect();
        if ($orderExternalIds !== []) {
            $ordersByExternal = Order::query()
                ->where('workspace_id', $workspaceId)
                ->where('connection_id', $connectionId)
                ->whereIn('external_order_id', $orderExternalIds)
                ->get(['id', 'external_order_id', 'status', 'total_amount'])
                ->keyBy('external_order_id');
        }

        $payments = $this->loadPayments(
            $workspaceId,
            $connectionId,
            $sourceIds,
            $orderExternalIds,
            $ordersByExternal,
            $from,
            $to,
        );

        $paymentsBySource = $payments
            ->filter(fn (MarketplacePayment $p) => is_string($p->external_payment_id) && $p->external_payment_id !== '')
            ->keyBy('external_payment_id');

        $groups = $this->finalizeGroups(
            $this->buildGroups($entries, $shippingMap, $ordersByExternal, $paymentsBySource),
        );

        $flatPayments = $this->flattenPayments($groups);

        $netTotal = CashMoney::zero();
        foreach ($entries as $entry) {
            $netTotal = CashMoney::add($netTotal, (string) $entry->net_amount);
        }

        $ordersCount = collect($groups)->filter(fn (array $g) => $this->groupCountsAsReleasedOrder($g))->count();

        return [
            'kind' => 'release_bucket',
            'id' => $bucketId,
            'label' => 'Liberación de dinero',
            'source' => $source,
            'connection' => [
                'id' => $connection->id,
                'display_name' => $connection->display_name,
            ],
            'release_hour' => $hour->toIso8601String(),
            'currency_code' => (string) ($entries->first()?->currency_code ?: 'MXN'),
            'net_total' => $netTotal,
            'entries_count' => $entries->count(),
            'payments_count' => $ordersCount,
            'truncated' => $entries->count() >= self::LIMIT || count($groups) >= self::LIMIT,
            'groups' => array_values($groups),
            'payments' => $flatPayments,
            'ledger_entries' => $entries->map(function (CashLedgerEntry $e) {
                $concept = CashMovementConcept::describe(
                    $e->transaction_type,
                    $e->entry_type,
                    $e->external_order_id ?? $e->external_reference,
                    (string) ($e->net_amount ?? ''),
                );

                return [
                    'id' => $e->id,
                    'external_source_id' => $e->external_source_id,
                    'external_order_id' => $e->external_order_id,
                    'transaction_type' => $e->transaction_type,
                    'concept' => $concept['key'],
                    'concept_label' => $concept['label'],
                    'concept_hint' => $concept['hint'],
                    'net_amount' => $e->net_amount,
                    'released_at' => ($e->released_at ?? $e->occurred_at)?->toIso8601String(),
                    'is_released' => $e->is_released,
                    'provenance' => $e->provenance,
                ];
            })->all(),
        ];
    }

    /**
     * @param  Collection<int, CashLedgerEntry>  $entries
     * @param  array<string, string>  $shippingMap
     * @param  Collection<string, Order>  $ordersByExternal
     * @param  Collection<string, MarketplacePayment>  $paymentsBySource
     * @return array<string, array<string, mixed>>
     */
    private function buildGroups(
        Collection $entries,
        array $shippingMap,
        Collection $ordersByExternal,
        Collection $paymentsBySource,
    ): array {
        $groups = [];

        foreach ($entries as $entry) {
            $ref = trim((string) ($entry->external_order_id ?? ''));
            $src = trim((string) ($entry->external_source_id ?? ''));
            $lookupId = $ref !== '' ? $ref : $src;
            $concept = CashMovementConcept::describe(
                $entry->transaction_type,
                $entry->entry_type,
                $lookupId,
                (string) ($entry->net_amount ?? ''),
            );

            $resolvedOrder = $this->resolvedOrderExternalId($ref, $src, $concept['key'], $shippingMap);

            if ($resolvedOrder !== null) {
                $groupKey = 'order:'.$resolvedOrder;
                $kind = 'order';
            } elseif ($concept['key'] === CashMovementConcept::SHIPPING_CREDIT) {
                $groupKey = 'shipping:'.($ref !== '' ? $ref : $src);
                $kind = 'shipping';
            } elseif ($concept['key'] === CashMovementConcept::CASHBACK) {
                $groupKey = 'cashback:'.($ref !== '' ? $ref : $src);
                $kind = 'cashback';
            } else {
                $groupKey = 'other:'.($src !== '' ? $src : ($ref !== '' ? $ref : 'e'.$entry->id));
                $kind = 'other';
            }

            if (! isset($groups[$groupKey])) {
                $order = $resolvedOrder !== null ? $ordersByExternal->get($resolvedOrder) : null;
                $groups[$groupKey] = [
                    'key' => $groupKey,
                    'kind' => $kind,
                    'external_order_id' => $resolvedOrder,
                    'display_reference' => $resolvedOrder ?: ($ref !== '' ? $ref : $src),
                    'order_id' => $order?->id,
                    'order_status' => $order?->status,
                    'net_total' => CashMoney::zero(),
                    'can_fetch_order' => $kind === 'order'
                        && $order === null
                        && CashMovementConcept::looksLikeMlOrderId($resolvedOrder),
                    'reconciliation_status' => $this->groupStatus($kind, $order !== null, null),
                    'has_shipping_credit' => false,
                    'has_dispute' => false,
                    'retained_amount' => CashMoney::zero(),
                    'available_amount' => CashMoney::zero(),
                    'payment_status' => null,
                    'marketplace_payment_id' => null,
                    'lines' => [],
                ];
            }

            $net = CashMoney::scale((string) $entry->net_amount) ?? CashMoney::zero();
            $groups[$groupKey]['net_total'] = CashMoney::add($groups[$groupKey]['net_total'], $net);
            if ($concept['key'] === CashMovementConcept::SHIPPING_CREDIT) {
                $groups[$groupKey]['has_shipping_credit'] = true;
            }

            $payment = $src !== '' ? $paymentsBySource->get($src) : null;
            if ($payment === null && $resolvedOrder !== null) {
                $order = $ordersByExternal->get($resolvedOrder);
                if ($order !== null) {
                    $payment = $paymentsBySource->first(
                        fn (MarketplacePayment $p) => (int) $p->order_id === (int) $order->id,
                    );
                }
            }

            if ($payment !== null) {
                $groups[$groupKey]['payment_status'] = $payment->status;
                if ($groups[$groupKey]['marketplace_payment_id'] === null) {
                    $groups[$groupKey]['marketplace_payment_id'] = $payment->id;
                    $groups[$groupKey]['reconciliation_status'] = $this->groupStatus(
                        $kind,
                        $groups[$groupKey]['order_id'] !== null,
                        $payment,
                    );
                }
            } elseif ($groups[$groupKey]['marketplace_payment_id'] === null) {
                $groups[$groupKey]['reconciliation_status'] = $this->groupStatus(
                    $kind,
                    $groups[$groupKey]['order_id'] !== null,
                    null,
                );
            }

            $groups[$groupKey]['lines'][] = [
                'concept' => $concept['key'],
                'concept_label' => $concept['label'],
                'concept_hint' => $concept['hint'],
                'transaction_type' => $entry->transaction_type,
                'external_source_id' => $src !== '' ? $src : null,
                'external_reference' => $ref !== '' ? $ref : null,
                'net_amount' => $entry->net_amount,
                'marketplace_payment_id' => $payment?->id,
                'ledger_entry_id' => $entry->id,
                'reference_kind' => CashMovementConcept::referenceKind($lookupId, $entry->transaction_type),
            ];
        }

        return $groups;
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @return array<string, array<string, mixed>>
     */
    private function finalizeGroups(array $groups): array
    {
        foreach ($groups as $key => $group) {
            $retained = CashMoney::zero();
            $hasDispute = false;
            foreach ($group['lines'] as $line) {
                if (($line['concept'] ?? '') === CashMovementConcept::DISPUTE) {
                    $hasDispute = true;
                }
                if (
                    in_array($line['concept'] ?? '', [
                        CashMovementConcept::DISPUTE,
                        CashMovementConcept::REFUND,
                        CashMovementConcept::CHARGEBACK,
                    ], true)
                    && isset($line['net_amount'])
                    && is_numeric($line['net_amount'])
                    && CashMoney::cmp((string) $line['net_amount'], '0') === -1
                ) {
                    $retained = CashMoney::add($retained, CashMoney::abs((string) $line['net_amount']));
                }
            }
            $groups[$key]['has_dispute'] = $hasDispute;
            $groups[$key]['retained_amount'] = $retained;
            $groups[$key]['available_amount'] = $group['net_total'];
            $payStatus = (string) ($group['payment_status'] ?? '');
            if ($hasDispute || $payStatus === 'in_mediation') {
                $groups[$key]['reconciliation_status'] = 'in_mediation';
            }
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function groupCountsAsReleasedOrder(array $group): bool
    {
        if (($group['kind'] ?? '') !== 'order') {
            return false;
        }
        if (($group['has_dispute'] ?? false) && CashMoney::cmp((string) ($group['net_total'] ?? '0'), '0') <= 0) {
            return false;
        }
        $concepts = collect($group['lines'] ?? [])->pluck('concept');
        if ($concepts->contains(CashMovementConcept::SALE) || $concepts->contains(CashMovementConcept::SHIPPING_CREDIT)) {
            return true;
        }

        return CashMoney::cmp((string) ($group['net_total'] ?? '0'), '0') === 1;
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function flattenPayments(array $groups): array
    {
        $rows = [];
        foreach ($groups as $group) {
            foreach ($group['lines'] as $line) {
                $rows[] = [
                    'id' => $line['marketplace_payment_id'],
                    'external_payment_id' => $line['external_source_id'],
                    'order_id' => $group['order_id'],
                    'external_order_id' => $group['external_order_id'] ?? $line['external_reference'],
                    'display_reference' => $line['external_reference'] ?? $group['display_reference'],
                    'reference_kind' => $line['reference_kind'],
                    'transaction_type' => $line['transaction_type'],
                    'order_status' => $group['order_status'],
                    'net_received_amount' => $line['net_amount'],
                    'reconciliation_status' => $group['reconciliation_status'],
                    'has_dispute' => $group['has_dispute'] ?? false,
                    'payment_status' => $group['payment_status'] ?? null,
                    'is_released' => true,
                    'money_release_at' => null,
                    'from_ledger_only' => $line['marketplace_payment_id'] === null,
                    'can_fetch_order' => $group['can_fetch_order'],
                    'concept' => $line['concept'],
                    'concept_label' => $line['concept_label'],
                    'concept_hint' => $line['concept_hint'],
                    'group_key' => $group['key'],
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $shippingMap
     */
    private function resolvedOrderExternalId(string $ref, string $src, string $concept, array $shippingMap): ?string
    {
        if (CashMovementConcept::looksLikeMlOrderId($ref)) {
            return $ref;
        }
        if ($ref !== '' && isset($shippingMap[$ref])) {
            return $shippingMap[$ref];
        }
        if ($src !== '' && isset($shippingMap[$src])) {
            return $shippingMap[$src];
        }
        if (CashMovementConcept::looksLikeShippingId($ref)) {
            return null;
        }
        if (
            $ref !== ''
            && ! in_array($concept, [
                CashMovementConcept::SHIPPING_CREDIT,
                CashMovementConcept::SHIPPING_DEBIT,
                CashMovementConcept::CASHBACK,
                CashMovementConcept::OTHER,
                CashMovementConcept::PAYOUT_HOLD,
            ], true)
        ) {
            return $ref;
        }

        return null;
    }

    private function groupStatus(string $kind, bool $hasLocalOrder, ?MarketplacePayment $payment): string
    {
        if ($kind === 'shipping') {
            return $hasLocalOrder ? 'shipping_linked' : 'shipping_release';
        }
        if ($kind === 'cashback' || $kind === 'other') {
            return 'non_order_reference';
        }
        if ($hasLocalOrder && $payment !== null) {
            $payStatus = (string) ($payment->status ?? '');
            if ($payStatus === 'in_mediation') {
                return 'in_mediation';
            }

            return (string) ($payment->reconciliation_status ?: 'linked_order');
        }
        if ($hasLocalOrder) {
            return 'linked_order';
        }

        return 'order_missing';
    }

    /**
     * @param  Collection<int, CashLedgerEntry>  $entries
     * @return array<string, string>
     */
    private function resolveShippingOrderMap(int $workspaceId, int $connectionId, Collection $entries): array
    {
        $shippingEntries = $entries->filter(function (CashLedgerEntry $e) {
            $ref = trim((string) ($e->external_order_id ?? ''));
            $concept = CashMovementConcept::fromLedger($e->transaction_type, $e->entry_type, $ref);

            return $concept === CashMovementConcept::SHIPPING_CREDIT
                || CashMovementConcept::looksLikeShippingId($ref);
        });

        if ($shippingEntries->isEmpty()) {
            return [];
        }

        $shippingIds = $shippingEntries
            ->pluck('external_order_id')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();
        $sourceIds = $shippingEntries
            ->pluck('external_source_id')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        $settlements = CashLedgerEntry::query()
            ->where('workspace_id', $workspaceId)
            ->where('connection_id', $connectionId)
            ->where('provenance', 'mp_settlement_report')
            ->whereNotNull('external_order_id')
            ->where('external_order_id', '!=', '')
            ->where(function ($q) use ($shippingIds, $sourceIds) {
                if ($shippingIds !== []) {
                    $q->whereIn('external_reference', $shippingIds)
                        ->orWhereIn('external_shipping_id', $shippingIds);
                }
                if ($sourceIds !== []) {
                    $q->orWhereIn('external_source_id', $sourceIds);
                }
            })
            ->where(function ($q) {
                $q->where('external_order_id', 'like', '20000%')
                    ->orWhereRaw('LENGTH(external_order_id) >= 15');
            })
            ->orderByDesc('id')
            ->get(['external_order_id', 'external_reference', 'external_shipping_id', 'external_source_id']);

        $map = [];
        foreach ($shippingEntries as $entry) {
            $ref = trim((string) ($entry->external_order_id ?? ''));
            $src = trim((string) ($entry->external_source_id ?? ''));
            $match = $settlements->first(function (CashLedgerEntry $row) use ($ref, $src) {
                if ($ref !== '' && ($row->external_reference === $ref || $row->external_shipping_id === $ref)) {
                    return true;
                }

                return $src !== ''
                    && $row->external_source_id === $src
                    && CashMovementConcept::looksLikeMlOrderId((string) $row->external_order_id);
            });
            if (! is_string($match?->external_order_id) || $match->external_order_id === '') {
                continue;
            }
            if ($ref !== '') {
                $map[$ref] = $match->external_order_id;
            }
            if ($src !== '') {
                $map[$src] = $match->external_order_id;
            }
        }

        return $map;
    }

    /**
     * @param  list<string>  $sourceIds
     * @param  list<string>  $orderExternalIds
     * @param  Collection<string, Order>  $ordersByExternal
     * @return Collection<int, MarketplacePayment>
     */
    private function loadPayments(
        int $workspaceId,
        int $connectionId,
        array $sourceIds,
        array $orderExternalIds,
        Collection $ordersByExternal,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): Collection {
        if ($sourceIds === [] && $orderExternalIds === [] && $ordersByExternal->isEmpty()) {
            return collect();
        }

        return MarketplacePayment::query()
            ->where('workspace_id', $workspaceId)
            ->where('connection_id', $connectionId)
            ->where(function ($q) use ($sourceIds, $orderExternalIds, $from, $to, $ordersByExternal) {
                $hasClause = false;
                if ($sourceIds !== []) {
                    $q->whereIn('external_payment_id', $sourceIds);
                    $hasClause = true;
                }
                $orderIds = $ordersByExternal->pluck('id')->filter()->values()->all();
                if ($orderIds !== []) {
                    $hasClause
                        ? $q->orWhereIn('order_id', $orderIds)
                        : $q->whereIn('order_id', $orderIds);
                    $hasClause = true;
                }
                if ($orderExternalIds !== []) {
                    $clause = function ($inner) use ($orderExternalIds) {
                        $inner->whereHas('order', fn ($oq) => $oq->whereIn('external_order_id', $orderExternalIds));
                    };
                    $hasClause ? $q->orWhere($clause) : $q->where($clause);
                    $hasClause = true;
                }
                $timeClause = function ($inner) use ($from, $to) {
                    $inner->whereNotNull('money_release_at')
                        ->whereBetween('money_release_at', [$from, $to]);
                };
                $hasClause ? $q->orWhere($timeClause) : $q->where($timeClause);
            })
            ->with(['order:id,external_order_id,status,total_amount'])
            ->orderByDesc('net_received_amount')
            ->limit(self::LIMIT)
            ->get()
            ->unique('id')
            ->values();
    }

    /**
     * @return array{0: int, 1: CarbonImmutable}
     */
    private function parseBucketId(string $bucketId): array
    {
        $parts = explode('|', $bucketId, 2);
        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || $parts[1] === '') {
            throw new InvalidArgumentException('Invalid release bucket id.');
        }

        return [(int) $parts[0], CarbonImmutable::parse($parts[1])->startOfHour()];
    }
}
