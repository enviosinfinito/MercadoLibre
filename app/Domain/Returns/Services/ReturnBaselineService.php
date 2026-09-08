<?php

namespace App\Domain\Returns\Services;

use App\Models\ReturnProductDailyStat;
use Illuminate\Support\Carbon;

final class ReturnBaselineService
{
    /**
     * @return array{mean: float|null, median: float|null, stddev: float|null, samples: int}
     */
    public function forProduct(
        int $workspaceId,
        ?int $productId,
        ?string $mlItemId,
        Carbon $asOf,
        int $lookbackDays = 90,
    ): array {
        $start = $asOf->copy()->subDays($lookbackDays)->startOfDay();

        $rates = ReturnProductDailyStat::query()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('date', [$start->toDateString(), $asOf->toDateString()])
            ->when($productId, fn ($q) => $q->where('product_id', $productId), fn ($q) => $q->where('ml_item_id', $mlItemId))
            ->where('units_sold', '>', 0)
            ->pluck('return_rate')
            ->map(fn ($r) => (float) $r)
            ->values()
            ->all();

        $n = count($rates);
        if ($n === 0) {
            return ['mean' => null, 'median' => null, 'stddev' => null, 'samples' => 0];
        }

        $mean = array_sum($rates) / $n;
        sort($rates);
        $median = $rates[(int) floor(($n - 1) / 2)];
        $variance = 0.0;
        foreach ($rates as $rate) {
            $variance += ($rate - $mean) ** 2;
        }
        $stddev = sqrt($variance / $n);

        return [
            'mean' => round($mean, 4),
            'median' => round($median, 4),
            'stddev' => round($stddev, 4),
            'samples' => $n,
        ];
    }
}
