<?php

namespace App\Domain\Cash\Actions;

use App\Domain\Cash\Support\OverdueReleaseQuery;
use App\Domain\Shared\Support\BusinessDay;
use App\Models\CashLedgerEntry;
use App\Models\CashReportFile;
use App\Models\MarketplacePayment;
use App\Models\Order;
use App\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class BuildOverdueReleaseCoverage
{
    public function __construct(
        private readonly OverdueReleaseQuery $query,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(int $workspaceId, ?int $connectionId = null): array
    {
        $tz = BusinessDay::timezone();
        $grace = $this->query->graceDays();
        $maxSyncDays = max(1, (int) config('finance.cash.release_sync_max_days', 14));

        $overdue = Order::query()->where('workspace_id', $workspaceId);
        if ($connectionId !== null) {
            $overdue->where('connection_id', $connectionId);
        }
        $this->query->constrain($overdue, OverdueReleaseQuery::KIND_OVERDUE);

        $overdueCount = (clone $overdue)->count();
        $connectionIds = (clone $overdue)
            ->whereNotNull('connection_id')
            ->distinct()
            ->pluck('connection_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $orderIds = (clone $overdue)->select('orders.id');

        $deliveredMin = Shipment::query()
            ->whereIn('order_id', $orderIds)
            ->where('status', 'delivered')
            ->whereNotNull('delivered_at')
            ->min('delivered_at');
        $deliveredMax = Shipment::query()
            ->whereIn('order_id', $orderIds)
            ->where('status', 'delivered')
            ->whereNotNull('delivered_at')
            ->max('delivered_at');

        $mlMin = MarketplacePayment::query()
            ->whereIn('order_id', $orderIds)
            ->whereNotNull('money_release_at')
            ->min('money_release_at');
        $mlMax = MarketplacePayment::query()
            ->whereIn('order_id', $orderIds)
            ->whereNotNull('money_release_at')
            ->max('money_release_at');

        $tentativeCandidates = [];
        if (is_string($deliveredMin) && $deliveredMin !== '') {
            $tentativeCandidates[] = CarbonImmutable::parse($deliveredMin)->addDays($grace);
        }
        if (is_string($mlMin) && $mlMin !== '') {
            $tentativeCandidates[] = CarbonImmutable::parse($mlMin);
        }
        if (is_string($deliveredMax) && $deliveredMax !== '') {
            $tentativeCandidates[] = CarbonImmutable::parse($deliveredMax)->addDays($grace);
        }
        if (is_string($mlMax) && $mlMax !== '') {
            $tentativeCandidates[] = CarbonImmutable::parse($mlMax);
        }

        $tentativeFrom = $tentativeCandidates === [] ? null : collect($tentativeCandidates)->min();
        $tentativeTo = $tentativeCandidates === [] ? null : collect($tentativeCandidates)->max();

        $coveredIntervals = $this->coveredIntervals($workspaceId, $connectionId, $tz);
        $gaps = [];
        if ($tentativeFrom instanceof CarbonImmutable && $tentativeTo instanceof CarbonImmutable) {
            $gaps = $this->gaps(
                $tentativeFrom->timezone($tz)->startOfDay(),
                $tentativeTo->timezone($tz)->startOfDay(),
                $coveredIntervals,
            );
        }

        [$syncFrom, $syncTo] = $this->defaultSyncRange($gaps, $tentativeFrom, $tentativeTo, $tz, $maxSyncDays);

        $coveredFrom = $coveredIntervals === [] ? null : $coveredIntervals[0]['from'];
        $coveredTo = $coveredIntervals === [] ? null : $coveredIntervals[count($coveredIntervals) - 1]['to'];

        return [
            'overdue_count' => $overdueCount,
            'grace_days' => $grace,
            'tentative_from' => $tentativeFrom?->timezone($tz)->toDateString(),
            'tentative_to' => $tentativeTo?->timezone($tz)->toDateString(),
            'covered_from' => $coveredFrom,
            'covered_to' => $coveredTo,
            'covered_intervals' => $coveredIntervals,
            'gaps' => $gaps,
            'sync_from' => $syncFrom,
            'sync_to' => $syncTo,
            'max_sync_days' => $maxSyncDays,
            'connection_ids' => $connectionIds,
        ];
    }

    /**
     * @return list<array{from: string, to: string}>
     */
    private function coveredIntervals(int $workspaceId, ?int $connectionId, string $tz): array
    {
        $files = CashReportFile::query()
            ->where('workspace_id', $workspaceId)
            ->when($connectionId !== null, fn ($q) => $q->where('connection_id', $connectionId))
            ->where('report_kind', 'release')
            ->whereNotNull('begin_date')
            ->whereNotNull('end_date')
            ->get(['begin_date', 'end_date']);

        /** @var Collection<int, array{0: CarbonImmutable, 1: CarbonImmutable}> $raw */
        $raw = collect();
        foreach ($files as $file) {
            if ($file->begin_date === null || $file->end_date === null) {
                continue;
            }
            $from = CarbonImmutable::parse($file->begin_date)->timezone($tz)->startOfDay();
            $to = CarbonImmutable::parse($file->end_date)->timezone($tz)->startOfDay();
            if ($from->gt($to)) {
                [$from, $to] = [$to, $from];
            }
            $raw->push([$from, $to]);
        }

        if ($raw->isEmpty()) {
            $ledgerQuery = CashLedgerEntry::query()
                ->where('workspace_id', $workspaceId)
                ->when($connectionId !== null, fn ($q) => $q->where('connection_id', $connectionId))
                ->where('provenance', 'mp_release_report')
                ->whereNotNull('occurred_at');
            $min = $ledgerQuery->min('occurred_at');
            $max = (clone $ledgerQuery)->max('occurred_at');
            if (is_string($min) && $min !== '' && is_string($max) && $max !== '') {
                $raw->push([
                    CarbonImmutable::parse($min)->timezone($tz)->startOfDay(),
                    CarbonImmutable::parse($max)->timezone($tz)->startOfDay(),
                ]);
            }
        }

        return $this->mergeDayIntervals($raw->all());
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $intervals
     * @return list<array{from: string, to: string}>
     */
    private function mergeDayIntervals(array $intervals): array
    {
        if ($intervals === []) {
            return [];
        }

        usort($intervals, fn ($a, $b) => $a[0]->timestamp <=> $b[0]->timestamp);

        $merged = [];
        foreach ($intervals as [$from, $to]) {
            if ($merged === []) {
                $merged[] = [$from, $to];

                continue;
            }
            $last = count($merged) - 1;
            if ($from->lte($merged[$last][1]->addDay())) {
                if ($to->gt($merged[$last][1])) {
                    $merged[$last][1] = $to;
                }

                continue;
            }
            $merged[] = [$from, $to];
        }

        return array_map(
            fn (array $pair) => [
                'from' => $pair[0]->toDateString(),
                'to' => $pair[1]->toDateString(),
            ],
            $merged,
        );
    }

    /**
     * @param  list<array{from: string, to: string}>  $covered
     * @return list<array{from: string, to: string}>
     */
    private function gaps(CarbonImmutable $spanFrom, CarbonImmutable $spanTo, array $covered): array
    {
        $cursor = $spanFrom->startOfDay();
        $end = $spanTo->startOfDay();
        $gaps = [];

        $coveredPairs = array_map(
            fn (array $row) => [
                CarbonImmutable::parse($row['from'], $spanFrom->timezoneName)->startOfDay(),
                CarbonImmutable::parse($row['to'], $spanFrom->timezoneName)->startOfDay(),
            ],
            $covered,
        );

        foreach ($coveredPairs as [$coverFrom, $coverTo]) {
            if ($coverTo->lt($cursor) || $coverFrom->gt($end)) {
                continue;
            }
            if ($coverFrom->gt($cursor)) {
                $gapEnd = $coverFrom->subDay();
                if ($gapEnd->gte($cursor)) {
                    $gaps[] = [
                        'from' => $cursor->toDateString(),
                        'to' => $gapEnd->toDateString(),
                    ];
                }
            }
            $next = $coverTo->addDay();
            if ($next->gt($cursor)) {
                $cursor = $next;
            }
            if ($cursor->gt($end)) {
                break;
            }
        }

        if ($cursor->lte($end)) {
            $gaps[] = [
                'from' => $cursor->toDateString(),
                'to' => $end->toDateString(),
            ];
        }

        return $gaps;
    }

    /**
     * @param  list<array{from: string, to: string}>  $gaps
     * @return array{0: string|null, 1: string|null}
     */
    private function defaultSyncRange(
        array $gaps,
        ?CarbonImmutable $tentativeFrom,
        ?CarbonImmutable $tentativeTo,
        string $tz,
        int $maxSyncDays,
    ): array {
        $source = null;
        if ($gaps !== []) {
            $source = $gaps[count($gaps) - 1];
        } elseif ($tentativeFrom !== null && $tentativeTo !== null) {
            $source = [
                'from' => $tentativeFrom->timezone($tz)->toDateString(),
                'to' => $tentativeTo->timezone($tz)->toDateString(),
            ];
        }

        if ($source === null) {
            return [null, null];
        }

        $from = CarbonImmutable::parse($source['from'], $tz)->startOfDay();
        $to = CarbonImmutable::parse($source['to'], $tz)->startOfDay();
        $spanDays = (int) $from->diffInDays($to, true) + 1;
        if ($spanDays > $maxSyncDays) {
            $from = $to->subDays($maxSyncDays - 1)->startOfDay();
        }

        return [$from->toDateString(), $to->toDateString()];
    }
}
