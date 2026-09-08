<?php

namespace App\Domain\Returns\Services;

use App\Models\ReturnCase;
use App\Models\ReturnCaseItem;
use App\Models\ReturnProductDailyStat;
use App\Models\Shipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ReturnVisualizationService
{
    public function __construct(
        private readonly ReturnProductAnalyticsService $products,
    ) {}

    /**
     * @param  list<int>|null  $connectionIds
     * @return array{items: list<array<string, mixed>>, cutoff_index: int}
     */
    public function pareto(int $workspaceId, Carbon $start, Carbon $end, ?array $connectionIds = null): array
    {
        $rows = $this->products->productRows($workspaceId, $start, $end, $connectionIds, limit: 100, sort: 'returned_amount');
        $total = max(0.01, array_sum(array_column($rows, 'returned_amount')));
        $cum = 0.0;
        $items = [];
        $cutoff = count($rows);

        foreach ($rows as $i => $row) {
            $cum += $row['returned_amount'];
            $share = $cum / $total;
            $items[] = [
                'name' => $row['name'],
                'product_id' => $row['product_id'],
                'ml_item_id' => $row['ml_item_id'],
                'amount' => $row['returned_amount'],
                'cumulative_share' => round($share, 4),
            ];
            if ($share >= 0.8 && $cutoff === count($rows)) {
                $cutoff = $i + 1;
            }
        }

        return ['items' => $items, 'cutoff_index' => $cutoff];
    }

    /**
     * @param  list<int>|null  $connectionIds
     * @return list<array<string, mixed>>
     */
    public function scatter(int $workspaceId, Carbon $start, Carbon $end, ?array $connectionIds = null): array
    {
        $rows = $this->products->productRows($workspaceId, $start, $end, $connectionIds, limit: 200);

        return array_map(fn (array $row) => [
            'name' => $row['name'],
            'product_id' => $row['product_id'],
            'ml_item_id' => $row['ml_item_id'],
            'x' => $row['units_sold'],
            'y' => round($row['return_rate'] * 100, 2),
            'z' => $row['returned_amount'],
            'risk_level' => $row['risk_level'],
        ], $rows);
    }

    /**
     * @param  list<int>|null  $connectionIds
     * @return array{products: list<string>, weeks: list<string>, matrix: list<list<float|null>>}
     */
    public function heatmap(int $workspaceId, Carbon $start, Carbon $end, ?array $connectionIds = null): array
    {
        $top = $this->products->productRows($workspaceId, $start, $end, $connectionIds, limit: 15);
        $weeks = [];
        $cursor = $start->copy()->startOfWeek();
        while ($cursor->lte($end)) {
            $weeks[] = $cursor->toDateString();
            $cursor->addWeek();
        }

        $products = [];
        $matrix = [];

        foreach ($top as $row) {
            $label = $row['name'] ?? 'Producto';
            $products[] = $label;
            $line = [];
            foreach ($weeks as $weekStart) {
                $weekEnd = Carbon::parse($weekStart)->endOfWeek()->toDateString();
                $avg = ReturnProductDailyStat::query()
                    ->where('workspace_id', $workspaceId)
                    ->whereBetween('date', [$weekStart, $weekEnd])
                    ->when(
                        $row['product_id'],
                        fn ($q) => $q->where('product_id', $row['product_id']),
                        fn ($q) => $q->where('ml_item_id', $row['ml_item_id']),
                    )
                    ->avg('return_rate');
                $line[] = $avg !== null ? round((float) $avg * 100, 2) : null;
            }
            $matrix[] = $line;
        }

        return ['products' => $products, 'weeks' => $weeks, 'matrix' => $matrix];
    }

    /**
     * @param  list<int>|null  $connectionIds
     * @return list<array{bucket: string, count: int, share: float}>
     */
    public function daysToReturnDistribution(
        int $workspaceId,
        Carbon $start,
        Carbon $end,
        ?array $connectionIds = null,
    ): array {
        $query = ReturnCase::query()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('opened_at', [$start, $end])
            ->whereNotNull('days_to_return');
        if ($connectionIds) {
            $query->whereIn('connection_id', $connectionIds);
        }

        $buckets = [
            '0-1' => 0,
            '2-3' => 0,
            '4-7' => 0,
            '8-14' => 0,
            '15+' => 0,
        ];

        foreach ($query->pluck('days_to_return') as $days) {
            $d = (int) $days;
            $key = match (true) {
                $d <= 1 => '0-1',
                $d <= 3 => '2-3',
                $d <= 7 => '4-7',
                $d <= 14 => '8-14',
                default => '15+',
            };
            $buckets[$key]++;
        }

        $total = max(1, array_sum($buckets));

        return collect($buckets)->map(fn ($count, $bucket) => [
            'bucket' => $bucket,
            'count' => $count,
            'share' => round($count / $total, 4),
        ])->values()->all();
    }

    /**
     * @param  list<int>|null  $connectionIds
     * @return list<array<string, mixed>>
     */
    public function logisticsCorrelation(
        int $workspaceId,
        Carbon $start,
        Carbon $end,
        ?array $connectionIds = null,
    ): array {
        $cases = ReturnCase::query()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('opened_at', [$start, $end])
            ->when($connectionIds, fn ($q) => $q->whereIn('connection_id', $connectionIds))
            ->whereNotNull('order_id')
            ->get(['id', 'order_id', 'inferred_reason_group', 'reason_group']);

        $orderIds = $cases->pluck('order_id')->filter()->unique()->values();
        $shipments = Shipment::query()
            ->whereIn('order_id', $orderIds)
            ->get(['order_id', 'carrier', 'meta'])
            ->keyBy('order_id');

        $bucket = [];
        foreach ($cases as $case) {
            $shipment = $shipments->get($case->order_id);
            $carrier = $shipment?->carrier ?: 'desconocido';
            $logistic = is_array($shipment?->meta) ? ($shipment->meta['logistic_type'] ?? 'n/d') : 'n/d';
            $key = $carrier.'|'.$logistic;
            if (! isset($bucket[$key])) {
                $bucket[$key] = [
                    'carrier' => $carrier,
                    'logistic_type' => $logistic,
                    'returns' => 0,
                    'damaged' => 0,
                ];
            }
            $bucket[$key]['returns']++;
            $group = $case->inferred_reason_group ?: $case->reason_group;
            if (in_array($group, ['defective', 'logistics'], true)) {
                $bucket[$key]['damaged']++;
            }
        }

        $rows = array_values($bucket);
        usort($rows, fn ($a, $b) => $b['returns'] <=> $a['returns']);

        return array_map(function (array $row) {
            $row['damaged_rate'] = $row['returns'] > 0 ? round($row['damaged'] / $row['returns'], 4) : 0;
            $row['note'] = 'Correlación observada; no implica causalidad.';

            return $row;
        }, array_slice($rows, 0, 20));
    }

    /**
     * @param  list<int>|null  $connectionIds
     * @return list<array<string, mixed>>
     */
    public function geoDistribution(
        int $workspaceId,
        Carbon $start,
        Carbon $end,
        ?array $connectionIds = null,
    ): array {
        $orderIds = ReturnCase::query()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('opened_at', [$start, $end])
            ->when($connectionIds, fn ($q) => $q->whereIn('connection_id', $connectionIds))
            ->whereNotNull('order_id')
            ->pluck('order_id');

        $shipments = Shipment::query()->whereIn('order_id', $orderIds)->get(['meta']);
        $bucket = [];

        foreach ($shipments as $shipment) {
            $address = is_array($shipment->meta) ? ($shipment->meta['receiver_address'] ?? null) : null;
            $state = is_array($address) ? ($address['state']['name'] ?? $address['state'] ?? null) : null;
            $city = is_array($address) ? ($address['city']['name'] ?? $address['city'] ?? null) : null;
            $key = (is_string($state) ? $state : 'Desconocido').'|'.(is_string($city) ? $city : '');
            $bucket[$key] = ($bucket[$key] ?? 0) + 1;
        }

        arsort($bucket);
        $rows = [];
        foreach (array_slice($bucket, 0, 30, true) as $key => $count) {
            [$state, $city] = array_pad(explode('|', (string) $key, 2), 2, '');
            $rows[] = [
                'state' => $state,
                'city' => $city !== '' ? $city : null,
                'returns' => $count,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<int>|null  $connectionIds
     * @return list<array<string, mixed>>
     */
    public function categories(
        int $workspaceId,
        Carbon $start,
        Carbon $end,
        ?array $connectionIds = null,
    ): array {
        $rows = $this->products->productRows($workspaceId, $start, $end, $connectionIds, limit: 500);
        $bucket = [];

        foreach ($rows as $row) {
            $cat = $row['category'] ?: 'Sin categoría';
            if (! isset($bucket[$cat])) {
                $bucket[$cat] = [
                    'category' => $cat,
                    'units_sold' => 0,
                    'returned_units' => 0,
                    'returned_amount' => 0.0,
                    'products' => 0,
                ];
            }
            $bucket[$cat]['units_sold'] += $row['units_sold'];
            $bucket[$cat]['returned_units'] += $row['returned_units'];
            $bucket[$cat]['returned_amount'] += $row['returned_amount'];
            $bucket[$cat]['products']++;
        }

        $out = [];
        foreach ($bucket as $row) {
            $rate = $row['units_sold'] > 0 ? $row['returned_units'] / $row['units_sold'] : 0;
            $out[] = $row + ['return_rate' => round($rate, 4)];
        }
        usort($out, fn ($a, $b) => $b['return_rate'] <=> $a['return_rate']);

        return $out;
    }
}
