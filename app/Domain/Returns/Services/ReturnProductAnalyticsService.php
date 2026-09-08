<?php

namespace App\Domain\Returns\Services;

use App\Domain\Sales\Support\OrderSalesClassification;
use App\Models\ChannelListing;
use App\Models\Connection;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\ReturnCase;
use App\Models\ReturnCaseItem;
use App\Models\ReturnProductDailyStat;
use App\Models\ReturnProductNarrative;
use App\Models\ReturnVariantDailyStat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ReturnProductAnalyticsService
{
    public function __construct(
        private readonly ReturnRiskScoreService $riskScoreService,
        private readonly ReturnBaselineService $baselineService,
    ) {}

    /**
     * @param  list<int>|null  $connectionIds
     * @return list<array<string, mixed>>
     */
    public function productRows(
        int $workspaceId,
        Carbon $start,
        Carbon $end,
        ?array $connectionIds = null,
        int $limit = 100,
        string $sort = 'risk_score',
        ?string $q = null,
        ?float $minRate = null,
        ?float $maxRate = null,
        bool $anomaliesOnly = false,
        bool $aboveHistoricalOnly = false,
    ): array {
        $itemsQuery = ReturnCaseItem::query()
            ->join('returns', 'returns.id', '=', 'return_case_items.return_id')
            ->where('return_case_items.workspace_id', $workspaceId)
            ->whereBetween('returns.opened_at', [$start, $end]);

        if ($connectionIds !== null && $connectionIds !== []) {
            $itemsQuery->whereIn('returns.connection_id', $connectionIds);
        }

        $grouped = $itemsQuery
            ->select([
                'return_case_items.product_id',
                'return_case_items.ml_item_id',
                DB::raw('MAX(return_case_items.sku) as sku'),
                DB::raw('MAX(return_case_items.title) as title'),
                DB::raw('SUM(return_case_items.quantity) as returned_units'),
                DB::raw('COUNT(DISTINCT returns.id) as return_count'),
                DB::raw('SUM(return_case_items.line_amount) as returned_amount'),
                DB::raw('MAX(COALESCE(returns.inferred_reason_group, returns.reason_group)) as dominant_reason_group'),
                DB::raw('COUNT(DISTINCT COALESCE(returns.inferred_reason_group, returns.reason_group)) as reason_variety'),
                DB::raw('MAX(returns.connection_id) as connection_id'),
            ])
            ->groupBy('return_case_items.product_id', 'return_case_items.ml_item_id')
            ->get();

        $connectionIdsForRows = $grouped->pluck('connection_id')->filter()->unique()->values()->all();
        $connectionsById = $connectionIdsForRows === []
            ? collect()
            : Connection::query()
                ->whereIn('id', $connectionIdsForRows)
                ->get(['id', 'provider', 'display_name', 'external_user_id', 'color'])
                ->keyBy('id');

        if ($grouped->isEmpty()) {
            return [];
        }

        $workspaceReturnedAmount = (float) $grouped->sum('returned_amount');
        $rows = [];

        foreach ($grouped as $g) {
            $sold = $this->soldForProduct(
                $workspaceId,
                $start,
                $end,
                $g->product_id,
                $g->ml_item_id,
                $connectionIds,
            );

            $unitsSold = (int) $sold['units_sold'];
            $grossSales = (float) $sold['gross_sales'];
            $returnedUnits = (int) $g->returned_units;
            $rate = $unitsSold > 0 ? $returnedUnits / $unitsSold : ($returnedUnits > 0 ? 1.0 : 0.0);

            $baseline = $this->baselineService->forProduct(
                $workspaceId,
                $g->product_id,
                $g->ml_item_id,
                $end,
            );

            $recent = $this->rateForWindow($workspaceId, $end->copy()->subDays(6)->startOfDay(), $end, $g->product_id, $g->ml_item_id, $connectionIds);
            $prior = $this->rateForWindow($workspaceId, $end->copy()->subDays(36)->startOfDay(), $end->copy()->subDays(7)->endOfDay(), $g->product_id, $g->ml_item_id, $connectionIds);

            $reasonShares = $this->reasonShares($workspaceId, $start, $end, $g->product_id, $g->ml_item_id, $connectionIds);
            $topShare = $reasonShares['top_share'];
            $defectiveShare = $reasonShares['defective_share'];

            $variantStats = $this->variantOutlier($workspaceId, $start, $end, $g->product_id, $g->ml_item_id);

            $risk = $this->riskScoreService->calculate([
                'return_rate' => $rate,
                'returned_units' => $returnedUnits,
                'units_sold' => $unitsSold,
                'returned_amount' => (float) $g->returned_amount,
                'workspace_returned_amount' => max(0.01, $workspaceReturnedAmount),
                'historical_rate' => $baseline['mean'],
                'recent_rate' => $recent,
                'prior_rate' => $prior,
                'top_reason_share' => $topShare,
                'defective_share' => $defectiveShare,
                'max_variant_rate' => $variantStats['max_rate'],
                'peer_variant_rate' => $variantStats['peer_rate'],
            ]);

            $product = $g->product_id ? Product::query()->find($g->product_id) : null;
            $listing = null;
            if (filled($g->ml_item_id)) {
                $listing = ChannelListing::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('external_item_id', $g->ml_item_id)
                    ->first(['id', 'title', 'pictures', 'category_name', 'external_item_id']);
            }

            $name = $product?->name ?: ($listing?->title ?: ($g->title ?: 'Producto'));
            $image = is_array($listing?->pictures) ? ($listing->pictures[0]['url'] ?? $listing->pictures[0] ?? null) : null;
            if (is_array($image)) {
                $image = $image['url'] ?? null;
            }

            $labels = config('returns.reason_groups', []);
            $sparkline = $this->sparkline($workspaceId, $g->product_id, $g->ml_item_id, $end);

            $narrative = ReturnProductNarrative::query()
                ->where('workspace_id', $workspaceId)
                ->when($g->product_id, fn ($q) => $q->where('product_id', $g->product_id), fn ($q) => $q->where('ml_item_id', $g->ml_item_id))
                ->orderByDesc('id')
                ->value('summary');

            $connection = $g->connection_id
                ? $connectionsById->get((int) $g->connection_id)
                : null;

            $row = [
                'product_id' => $g->product_id,
                'ml_item_id' => $g->ml_item_id,
                'sku' => $g->sku,
                'name' => $name,
                'image' => $image,
                'category' => $listing?->category_name,
                'connection_id' => $connection?->id,
                'connection' => $connection ? [
                    'id' => $connection->id,
                    'provider' => $connection->provider,
                    'display_name' => $connection->display_name,
                    'external_user_id' => $connection->external_user_id,
                    'color' => $connection->color,
                ] : null,
                'units_sold' => $unitsSold,
                'gross_sales' => round($grossSales, 2),
                'returned_units' => $returnedUnits,
                'return_count' => (int) $g->return_count,
                'return_rate' => round($rate, 4),
                'returned_amount' => round((float) $g->returned_amount, 2),
                'estimated_loss' => round((float) $g->returned_amount, 2),
                'dominant_reason_group' => $g->dominant_reason_group,
                'dominant_reason_label' => $labels[$g->dominant_reason_group] ?? $g->dominant_reason_group,
                'reason_variety' => (int) $g->reason_variety,
                'top_variant_label' => $variantStats['top_label'],
                'sparkline' => $sparkline,
                'risk_score' => $risk['score'],
                'risk_level' => $risk['level'],
                'confidence' => $risk['confidence'],
                'risk_factors' => $risk['factors'],
                'historical_rate' => $baseline['mean'],
                'narrative' => $narrative,
                'insufficient_sample' => $unitsSold < (int) config('returns.sample.min_sales_for_patterns', 10),
            ];

            if ($q !== null && $q !== '') {
                $hay = mb_strtolower(($row['name'] ?? '').' '.($row['sku'] ?? '').' '.($row['ml_item_id'] ?? ''));
                if (! str_contains($hay, mb_strtolower($q))) {
                    continue;
                }
            }
            if ($minRate !== null && $row['return_rate'] < $minRate) {
                continue;
            }
            if ($maxRate !== null && $row['return_rate'] > $maxRate) {
                continue;
            }
            if ($anomaliesOnly && ! in_array($row['risk_level'], ['attention', 'high', 'critical'], true)) {
                continue;
            }
            if ($aboveHistoricalOnly && ($baseline['mean'] === null || $row['return_rate'] <= $baseline['mean'])) {
                continue;
            }

            $rows[] = $row;
        }

        usort($rows, function (array $a, array $b) use ($sort): int {
            $key = match ($sort) {
                'return_rate' => 'return_rate',
                'returned_amount' => 'returned_amount',
                'returned_units' => 'returned_units',
                default => 'risk_score',
            };

            return ($b[$key] <=> $a[$key]) ?: ($b['returned_amount'] <=> $a['returned_amount']);
        });

        return array_slice($rows, 0, $limit);
    }

    /**
     * @param  list<int>|null  $connectionIds
     * @return array<string, mixed>
     */
    public function productDetail(
        int $workspaceId,
        ?int $productId,
        ?string $mlItemId,
        Carbon $start,
        Carbon $end,
        Carbon $prevStart,
        Carbon $prevEnd,
        ?array $connectionIds = null,
    ): array {
        $rows = $this->productRows($workspaceId, $start, $end, $connectionIds, limit: 500);
        $row = collect($rows)->first(function (array $r) use ($productId, $mlItemId) {
            if ($productId !== null) {
                return (int) $r['product_id'] === $productId;
            }

            return ($r['ml_item_id'] ?? null) === $mlItemId;
        });

        $series = ReturnProductDailyStat::query()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->when($productId, fn ($q) => $q->where('product_id', $productId), fn ($q) => $q->where('ml_item_id', $mlItemId))
            ->orderBy('date')
            ->get(['date', 'units_sold', 'returned_units', 'return_rate', 'returned_amount']);

        $variants = app(ReturnVariantAnalysisService::class)
            ->forProduct($workspaceId, $productId, $mlItemId, $start, $end, $connectionIds);

        $reasons = app(ReturnReasonAnalysisService::class)
            ->forProduct($workspaceId, $productId, $mlItemId, $start, $end, $connectionIds);

        $comparison = app(BuildReturnPeriodComparison::class)
            ->forProduct($workspaceId, $productId, $mlItemId, $start, $end, $prevStart, $prevEnd, $connectionIds);

        $cases = ReturnCase::query()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('opened_at', [$start, $end])
            ->when($productId, fn ($q) => $q->where('dominant_product_id', $productId), fn ($q) => $q->where('dominant_ml_item_id', $mlItemId))
            ->when($connectionIds, fn ($q) => $q->whereIn('connection_id', $connectionIds))
            ->orderByDesc('opened_at')
            ->limit(50)
            ->get()
            ->map(fn (ReturnCase $c) => [
                'id' => $c->id,
                'opened_at' => $c->opened_at,
                'outcome' => $c->outcome,
                'order_id' => $c->order_id,
                'returned_amount' => (float) $c->returned_amount,
                'inferred_reason_label' => $c->inferred_reason_label,
                'analysis_summary' => $c->analysis_summary,
                'analysis_source' => $c->analysis_source,
                'days_to_return' => $c->days_to_return,
            ]);

        $baseline = $this->baselineService->forProduct($workspaceId, $productId, $mlItemId, $end);

        return [
            'product' => $row,
            'series' => $series,
            'variants' => $variants,
            'reasons' => $reasons,
            'comparison' => $comparison,
            'cases' => $cases,
            'baseline' => $baseline,
            'historical_avg' => $baseline['mean'],
        ];
    }

    /**
     * @param  list<int>|null  $connectionIds
     * @return array{units_sold: int, gross_sales: float}
     */
    private function soldForProduct(
        int $workspaceId,
        Carbon $start,
        Carbon $end,
        ?int $productId,
        ?string $mlItemId,
        ?array $connectionIds,
    ): array {
        $query = OrderLine::query()
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->leftJoin('variants', 'variants.id', '=', 'order_lines.variant_id')
            ->where('order_lines.workspace_id', $workspaceId)
            ->whereBetween('orders.ordered_at', [$start, $end])
            ->whereNotIn('orders.status', OrderSalesClassification::cancelledStatuses());

        if ($connectionIds !== null && $connectionIds !== []) {
            $query->whereIn('order_lines.connection_id', $connectionIds);
        }
        if ($productId !== null) {
            $query->where('variants.product_id', $productId);
        } elseif (filled($mlItemId)) {
            $query->where('order_lines.external_item_id', $mlItemId);
        }

        return [
            'units_sold' => (int) (clone $query)->sum('order_lines.quantity'),
            'gross_sales' => (float) (clone $query)->sum('order_lines.line_total_amount'),
        ];
    }

    /**
     * @param  list<int>|null  $connectionIds
     */
    private function rateForWindow(
        int $workspaceId,
        Carbon $start,
        Carbon $end,
        ?int $productId,
        ?string $mlItemId,
        ?array $connectionIds,
    ): ?float {
        $sold = $this->soldForProduct($workspaceId, $start, $end, $productId, $mlItemId, $connectionIds);
        if ($sold['units_sold'] <= 0) {
            return null;
        }

        $q = ReturnCaseItem::query()
            ->join('returns', 'returns.id', '=', 'return_case_items.return_id')
            ->where('return_case_items.workspace_id', $workspaceId)
            ->whereBetween('returns.opened_at', [$start, $end]);
        if ($productId !== null) {
            $q->where('return_case_items.product_id', $productId);
        } else {
            $q->where('return_case_items.ml_item_id', $mlItemId);
        }
        if ($connectionIds !== null && $connectionIds !== []) {
            $q->whereIn('returns.connection_id', $connectionIds);
        }
        $returned = (int) $q->sum('return_case_items.quantity');

        return $returned / $sold['units_sold'];
    }

    /**
     * @param  list<int>|null  $connectionIds
     * @return array{top_share: float, defective_share: float}
     */
    private function reasonShares(
        int $workspaceId,
        Carbon $start,
        Carbon $end,
        ?int $productId,
        ?string $mlItemId,
        ?array $connectionIds,
    ): array {
        $q = ReturnCase::query()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('opened_at', [$start, $end]);
        if ($productId !== null) {
            $q->where('dominant_product_id', $productId);
        } else {
            $q->where('dominant_ml_item_id', $mlItemId);
        }
        if ($connectionIds !== null && $connectionIds !== []) {
            $q->whereIn('connection_id', $connectionIds);
        }

        $groups = $q->get(['inferred_reason_group', 'reason_group']);
        $total = max(1, $groups->count());
        $counts = [];
        foreach ($groups as $g) {
            $key = $g->inferred_reason_group ?: ($g->reason_group ?: 'other');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        arsort($counts);
        $top = $counts !== [] ? (int) reset($counts) : 0;

        return [
            'top_share' => $top / $total,
            'defective_share' => ($counts['defective'] ?? 0) / $total,
        ];
    }

    /**
     * @return array{max_rate: float|null, peer_rate: float|null, top_label: string|null}
     */
    private function variantOutlier(
        int $workspaceId,
        Carbon $start,
        Carbon $end,
        ?int $productId,
        ?string $mlItemId,
    ): array {
        $stats = ReturnVariantDailyStat::query()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->when($productId, fn ($q) => $q->where('product_id', $productId), fn ($q) => $q->where('ml_item_id', $mlItemId))
            ->select([
                'variant_id',
                'ml_variation_id',
                'variant_label',
                DB::raw('SUM(units_sold) as units_sold'),
                DB::raw('SUM(returned_units) as returned_units'),
            ])
            ->groupBy('variant_id', 'ml_variation_id', 'variant_label')
            ->get();

        if ($stats->isEmpty()) {
            return ['max_rate' => null, 'peer_rate' => null, 'top_label' => null];
        }

        $rates = [];
        $topLabel = null;
        $maxRate = null;
        foreach ($stats as $s) {
            $sold = max(1, (int) $s->units_sold);
            $rate = (int) $s->returned_units / $sold;
            $rates[] = $rate;
            if ($maxRate === null || $rate > $maxRate) {
                $maxRate = $rate;
                $topLabel = $s->variant_label ?: ($s->ml_variation_id ?: null);
            }
        }
        sort($rates);
        $mid = $rates[(int) floor((count($rates) - 1) / 2)] ?? null;

        return ['max_rate' => $maxRate, 'peer_rate' => $mid, 'top_label' => $topLabel];
    }

    /**
     * @return list<float>
     */
    private function sparkline(int $workspaceId, ?int $productId, ?string $mlItemId, Carbon $end): array
    {
        $start = $end->copy()->subDays(13)->startOfDay();
        $rows = ReturnProductDailyStat::query()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->when($productId, fn ($q) => $q->where('product_id', $productId), fn ($q) => $q->where('ml_item_id', $mlItemId))
            ->orderBy('date')
            ->pluck('return_rate', 'date');

        $points = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = $end->copy()->subDays($i)->toDateString();
            $points[] = (float) ($rows[$d] ?? 0);
        }

        return $points;
    }
}
