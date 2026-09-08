<?php

namespace App\Domain\Returns\Services;

use Illuminate\Support\Carbon;

final class BuildReturnPeriodComparison
{
    public function __construct(
        private readonly ReturnAnalyticsService $analytics,
        private readonly ReturnReasonAnalysisService $reasons,
        private readonly ReturnProductAnalyticsService $products,
    ) {}

    /**
     * @param  list<int>|null  $connectionIds
     * @return array<string, mixed>
     */
    public function global(
        int $workspaceId,
        Carbon $start,
        Carbon $end,
        Carbon $prevStart,
        Carbon $prevEnd,
        ?array $connectionIds = null,
    ): array {
        $current = $this->analytics->windowMetrics($workspaceId, $start, $end, $connectionIds);
        $previous = $this->analytics->windowMetrics($workspaceId, $prevStart, $prevEnd, $connectionIds);
        $currentReasons = collect($this->reasons->global($workspaceId, $start, $end, $connectionIds))->keyBy('group');
        $previousReasons = collect($this->reasons->global($workspaceId, $prevStart, $prevEnd, $connectionIds))->keyBy('group');

        $reasonDeltas = [];
        foreach ($currentReasons as $group => $row) {
            $prevShare = (float) ($previousReasons[$group]['share'] ?? 0);
            $reasonDeltas[] = [
                'group' => $group,
                'label' => $row['label'],
                'from' => $prevShare,
                'to' => (float) $row['share'],
            ];
        }

        return [
            'units_sold' => [
                'from' => $previous['units_sold'],
                'to' => $current['units_sold'],
                'delta_pct' => $this->delta($current['units_sold'], $previous['units_sold']),
            ],
            'returns' => [
                'from' => $previous['return_count'],
                'to' => $current['return_count'],
                'delta_pct' => $this->delta($current['return_count'], $previous['return_count']),
            ],
            'return_rate' => [
                'from' => $previous['return_rate'],
                'to' => $current['return_rate'],
            ],
            'reasons' => $reasonDeltas,
        ];
    }

    /**
     * @param  list<int>|null  $connectionIds
     * @return array<string, mixed>
     */
    public function forProduct(
        int $workspaceId,
        ?int $productId,
        ?string $mlItemId,
        Carbon $start,
        Carbon $end,
        Carbon $prevStart,
        Carbon $prevEnd,
        ?array $connectionIds = null,
    ): array {
        $currentRows = $this->products->productRows($workspaceId, $start, $end, $connectionIds, limit: 500);
        $previousRows = $this->products->productRows($workspaceId, $prevStart, $prevEnd, $connectionIds, limit: 500);

        $pick = function (array $rows) use ($productId, $mlItemId): ?array {
            return collect($rows)->first(function (array $r) use ($productId, $mlItemId) {
                if ($productId !== null) {
                    return (int) $r['product_id'] === $productId;
                }

                return ($r['ml_item_id'] ?? null) === $mlItemId;
            });
        };

        $current = $pick($currentRows);
        $previous = $pick($previousRows);

        return [
            'units_sold' => [
                'from' => $previous['units_sold'] ?? 0,
                'to' => $current['units_sold'] ?? 0,
                'delta_pct' => $this->delta($current['units_sold'] ?? 0, $previous['units_sold'] ?? 0),
            ],
            'returns' => [
                'from' => $previous['return_count'] ?? 0,
                'to' => $current['return_count'] ?? 0,
                'delta_pct' => $this->delta($current['return_count'] ?? 0, $previous['return_count'] ?? 0),
            ],
            'return_rate' => [
                'from' => $previous['return_rate'] ?? 0,
                'to' => $current['return_rate'] ?? 0,
            ],
            'reasons' => $this->reasons->forProduct($workspaceId, $productId, $mlItemId, $start, $end, $connectionIds),
        ];
    }

    private function delta(float|int $current, float|int $previous): ?float
    {
        if ((float) $previous === 0.0) {
            return $current > 0 ? 100.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
