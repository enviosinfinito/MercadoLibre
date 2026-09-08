<?php

namespace App\Domain\Cash\Actions;

use App\Domain\Cash\Support\CashMoney;
use App\Domain\Cash\Support\CashMovementConcept;
use App\Models\CashLedgerEntry;
use App\Models\MarketplacePayment;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hourly "Liberación de dinero" buckets (same idea as MP activity calendar).
 *
 * Prefers official release-report ledger rows; falls back to settlement MONEY_RELEASE_DATE.
 */
final class BuildCashReleaseBuckets
{
    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function execute(
        int $workspaceId,
        ?int $connectionId = null,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        int $perPage = 40,
        int $page = 1,
    ): LengthAwarePaginator {
        $tz = (string) config('app.business_timezone', config('app.timezone', 'UTC'));
        $from ??= CarbonImmutable::now($tz)->subDays(14)->startOfDay();
        $to ??= CarbonImmutable::now($tz)->endOfDay();

        $official = $this->aggregateBuckets(
            $workspaceId,
            $connectionId,
            $from,
            $to,
            provenance: 'mp_release_report',
            entryType: 'release',
            dateColumn: 'occurred_at',
            source: 'official_release',
        );

        $estimate = $this->aggregateBuckets(
            $workspaceId,
            $connectionId,
            $from,
            $to,
            provenance: 'mp_settlement_report',
            entryType: 'settlement',
            dateColumn: 'released_at',
            source: 'settlement_estimate',
        );

        /** @var Collection<string, array<string, mixed>> $merged */
        $merged = collect();
        foreach ($official as $row) {
            $merged->put($row['id'], $row);
        }
        foreach ($estimate as $row) {
            if (! $merged->has($row['id'])) {
                $merged->put($row['id'], $row);
            }
        }

        $sorted = $merged
            ->sortByDesc(fn (array $row) => $row['release_hour'])
            ->values();

        $total = $sorted->count();
        $items = $sorted
            ->forPage($page, $perPage)
            ->map(function (array $row) use ($workspaceId): array {
                $hour = CarbonImmutable::parse((string) $row['release_hour_raw']);
                $connId = (int) $row['connection_id'];
                $ordersCount = $this->countOrdersForBucket(
                    $workspaceId,
                    $connId,
                    $hour,
                    (string) $row['source'],
                );

                return [
                    'id' => $row['id'],
                    'connection_id' => $connId,
                    'release_hour' => $hour->toIso8601String(),
                    'label' => 'Liberación de dinero',
                    'source' => $row['source'],
                    'entries_count' => (int) $row['entries_count'],
                    'payments_count' => $ordersCount,
                    'net_total' => CashMoney::scale((string) ($row['net_total'] ?? '0')) ?? CashMoney::zero(),
                    'currency_code' => (string) ($row['currency_code'] ?: 'MXN'),
                ];
            })
            ->values();

        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'query' => request()->query(),
            ],
        );
    }

    /**
     * Distinct ML sales in the hour (shipping credits fold into their order).
     * Fallback: marketplace payments with money_release_at in that hour.
     */
    private function countOrdersForBucket(
        int $workspaceId,
        int $connectionId,
        CarbonImmutable $hour,
        string $source,
    ): int {
        $from = $hour;
        $to = $hour->endOfHour();
        $isOfficial = $source === 'official_release';
        $dateColumn = $isOfficial ? 'occurred_at' : 'released_at';
        $provenance = $isOfficial ? 'mp_release_report' : 'mp_settlement_report';
        $entryType = $isOfficial ? 'release' : 'settlement';

        $rows = DB::table('cash_ledger_entries')
            ->where('workspace_id', $workspaceId)
            ->where('connection_id', $connectionId)
            ->where('entry_type', $entryType)
            ->where('provenance', $provenance)
            ->whereNotNull($dateColumn)
            ->whereBetween($dateColumn, [$from->toDateTimeString(), $to->toDateTimeString()])
            ->whereNotNull('external_order_id')
            ->where('external_order_id', '!=', '')
            ->get(['external_order_id', 'transaction_type', 'external_source_id', 'net_amount']);

        if ($rows->isEmpty()) {
            return MarketplacePayment::query()
                ->where('workspace_id', $workspaceId)
                ->where('connection_id', $connectionId)
                ->whereNotNull('money_release_at')
                ->whereBetween('money_release_at', [$from, $to])
                ->count();
        }

        $orderKeys = [];
        $orderNets = [];
        $orderHasDispute = [];
        $shippingToResolve = [];
        foreach ($rows as $row) {
            $externalId = (string) $row->external_order_id;
            $concept = CashMovementConcept::fromLedger(
                (string) $row->transaction_type,
                $entryType,
                $externalId,
            );
            $net = CashMoney::scale((string) ($row->net_amount ?? '0')) ?? CashMoney::zero();
            if (
                $concept === CashMovementConcept::SHIPPING_CREDIT
                || CashMovementConcept::looksLikeShippingId($externalId)
            ) {
                $shippingToResolve[] = $row;

                continue;
            }
            if ($concept === CashMovementConcept::DISPUTE) {
                if (CashMovementConcept::looksLikeMlOrderId($externalId)) {
                    $orderHasDispute[$externalId] = true;
                    $orderNets[$externalId] = CashMoney::add($orderNets[$externalId] ?? CashMoney::zero(), $net);
                }

                continue;
            }
            if (in_array($concept, [
                CashMovementConcept::CASHBACK,
                CashMovementConcept::OTHER,
                CashMovementConcept::PAYOUT_HOLD,
                CashMovementConcept::REFUND,
                CashMovementConcept::CHARGEBACK,
            ], true)) {
                continue;
            }
            $orderKeys[$externalId] = true;
            $orderNets[$externalId] = CashMoney::add($orderNets[$externalId] ?? CashMoney::zero(), $net);
        }

        if ($shippingToResolve !== []) {
            foreach ($this->mapShippingToOrders($workspaceId, $connectionId, $shippingToResolve) as $mlOrderId) {
                $orderKeys[$mlOrderId] = true;
            }
        }

        foreach ($orderKeys as $orderId => $_) {
            if (($orderHasDispute[$orderId] ?? false) && CashMoney::cmp($orderNets[$orderId] ?? CashMoney::zero(), '0') <= 0) {
                unset($orderKeys[$orderId]);
            }
        }

        return count($orderKeys);
    }

    /**
     * @param  list<object{external_order_id: mixed, external_source_id: mixed}>  $shippingRows
     * @return list<string>
     */
    private function mapShippingToOrders(int $workspaceId, int $connectionId, array $shippingRows): array
    {
        $shippingIds = collect($shippingRows)
            ->pluck('external_order_id')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();
        $sourceIds = collect($shippingRows)
            ->pluck('external_source_id')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        if ($shippingIds === [] && $sourceIds === []) {
            return [];
        }

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
            ->get(['external_order_id']);

        return $settlements
            ->pluck('external_order_id')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aggregateBuckets(
        int $workspaceId,
        ?int $connectionId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $provenance,
        string $entryType,
        string $dateColumn,
        string $source,
    ): array {
        $driver = DB::connection()->getDriverName();
        $hourExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m-%d %H:00:00', {$dateColumn})"
            : "DATE_FORMAT({$dateColumn}, '%Y-%m-%d %H:00:00')";

        $rows = DB::table('cash_ledger_entries')
            ->where('workspace_id', $workspaceId)
            ->where('entry_type', $entryType)
            ->where('provenance', $provenance)
            ->whereNotNull($dateColumn)
            ->whereBetween($dateColumn, [$from->toDateTimeString(), $to->toDateTimeString()])
            ->when($connectionId, fn ($q) => $q->where('connection_id', $connectionId))
            ->selectRaw("{$hourExpr} as release_hour")
            ->selectRaw('connection_id')
            ->selectRaw('COUNT(*) as entries_count')
            ->selectRaw('SUM(net_amount) as net_total')
            ->selectRaw('MAX(currency_code) as currency_code')
            ->groupByRaw("{$hourExpr}, connection_id")
            ->get();

        return $rows->map(function (object $row) use ($source): array {
            $hour = CarbonImmutable::parse((string) $row->release_hour);
            $connectionId = (int) $row->connection_id;

            return [
                'id' => $connectionId.'|'.$hour->format('Y-m-d\TH:00:00'),
                'connection_id' => $connectionId,
                'release_hour' => $hour->toIso8601String(),
                'release_hour_raw' => (string) $row->release_hour,
                'source' => $source,
                'entries_count' => (int) $row->entries_count,
                'net_total' => (string) ($row->net_total ?? '0'),
                'currency_code' => (string) ($row->currency_code ?: 'MXN'),
            ];
        })->all();
    }
}
