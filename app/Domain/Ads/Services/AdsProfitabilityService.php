<?php

namespace App\Domain\Ads\Services;

use App\Models\AdSpendDaily;
use App\Models\ChannelListing;
use App\Models\ChannelListingVariant;
use App\Models\Order;
use App\Models\OrderLine;
use Illuminate\Support\Carbon;

/**
 * Margin-anchored ROAS/TACOS targets + waste scorecard for Product Ads.
 */
final class AdsProfitabilityService
{
    public const TARGET_ROAS_CAP = 35.0;

    /** Shared with chart "near" zone: below this ratio of target → Actuar (red). */
    public const NEAR_TARGET_RATIO = 0.7;

    /**
     * @return array{
     *     period: array{start: string, end: string, days: int},
     *     catalog_target_roas: float,
     *     catalog_target_acos: float,
     *     ads_budget_share_of_margin: float,
     *     avg_margin_rate: float,
     *     near_target_ratio: float,
     *     kpis: array<string, float|int>,
     *     scorecard: list<array<string, mixed>>,
     *     traffic_lights: array{green: int, yellow: int, red: int}
     * }
     */
    public function scorecard(int $workspaceId, ?int $lookbackDays = null): array
    {
        $days = $lookbackDays ?? (int) config('ads.evaluate_lookback_days', 14);
        $end = now()->startOfDay();
        $start = $end->copy()->subDays(max(1, $days) - 1);
        $share = max(0.05, min(0.9, (float) config('ads.ads_budget_share_of_margin', 0.35)));

        $itemRows = AdSpendDaily::query()
            ->where('workspace_id', $workspaceId)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->selectRaw('
                ml_item_id,
                connection_id,
                MAX(ad_campaign_id) as ad_campaign_id,
                MAX(external_campaign_id) as external_campaign_id,
                COALESCE(SUM(cost), 0) as cost,
                COALESCE(SUM(clicks), 0) as clicks,
                COALESCE(SUM(prints), 0) as prints,
                COALESCE(SUM(total_amount), 0) as attributed_revenue,
                COALESCE(SUM(direct_units_quantity + indirect_units_quantity), 0) as attributed_units,
                MAX(date) as last_spend_date
            ')
            ->groupBy('ml_item_id', 'connection_id')
            ->get();

        $itemIds = $itemRows->pluck('ml_item_id')->filter()->unique()->values()->all();
        $marginMeta = $this->marginMetaByItem($workspaceId, $itemIds, $start, $end);
        $stockByItem = $this->stockByItem($workspaceId, $itemIds);
        $listingsByItem = $this->listingsByItem($workspaceId, $itemIds);
        $gmvTotal = $this->workspaceGmv($workspaceId, $start, $end);

        $scorecard = [];
        $lights = ['green' => 0, 'yellow' => 0, 'red' => 0];
        $totalCost = 0.0;
        $totalAttr = 0.0;
        $wasteSpend = 0.0;
        $marginRates = [];

        foreach ($itemRows as $row) {
            $mlItemId = (string) $row->ml_item_id;
            $cost = (float) $row->cost;
            $attr = (float) $row->attributed_revenue;
            $units = (float) $row->attributed_units;
            $clicks = (int) $row->clicks;
            $meta = $marginMeta[$mlItemId] ?? [
                'margin_rate' => $this->defaultMarginRate(),
                'confidence' => 'low',
            ];
            $marginRate = (float) $meta['margin_rate'];
            $confidence = (string) $meta['confidence'];
            $marginRates[] = $marginRate;

            $targets = $this->resolveTargets($marginRate, $share, $confidence);
            $targetRoas = $targets['target_roas'];
            $targetAcos = $targetRoas > 0 ? (1 / $targetRoas) : 1.0;
            $roas = $cost > 0 ? ($attr / $cost) : 0.0;
            $acos = $attr > 0 ? ($cost / $attr) : ($cost > 0 ? 1.0 : 0.0);
            $waste = $units <= 0 && $cost > 0;
            $stock = $stockByItem[$mlItemId] ?? null;

            $status = $this->trafficLight($roas, $targetRoas, $waste, $stock, $cost, $clicks);
            $lights[$status]++;

            $totalCost += $cost;
            $totalAttr += $attr;
            if ($waste) {
                $wasteSpend += $cost;
            }

            $listing = $listingsByItem[$mlItemId] ?? null;
            $statusLabel = $this->statusLabel($status);
            $statusReason = $this->statusReason(
                $status,
                $waste,
                $stock,
                $cost,
                $clicks,
                $roas,
                $targetRoas,
                $targets['target_unreliable'],
            );

            $scorecard[] = [
                'ml_item_id' => $mlItemId,
                'title' => $listing['title'] ?? null,
                'permalink' => $listing['permalink'] ?? null,
                'product_id' => $listing['product_id'] ?? null,
                'connection_id' => (int) $row->connection_id,
                'ad_campaign_id' => $row->ad_campaign_id ? (int) $row->ad_campaign_id : null,
                'external_campaign_id' => $row->external_campaign_id ? (string) $row->external_campaign_id : null,
                'cost' => round($cost, 2),
                'clicks' => $clicks,
                'prints' => (int) $row->prints,
                'attributed_revenue' => round($attr, 2),
                'attributed_units' => round($units, 2),
                'roas' => round($roas, 2),
                'acos' => round($acos, 4),
                'margin_rate' => round($marginRate, 4),
                'margin_pct_display' => round($marginRate * 100, 1),
                'margin_confidence' => $confidence,
                'target_roas' => $targetRoas,
                'target_roas_raw' => $targets['target_roas_raw'],
                'target_acos' => round($targetAcos, 4),
                'max_acos_pct_display' => round($targetAcos * 100, 1),
                'target_roas_source' => $targets['target_roas_source'],
                'target_unreliable' => $targets['target_unreliable'],
                'roas_vs_target' => $targetRoas > 0 ? round($roas / $targetRoas, 2) : null,
                'near_target_ratio' => self::NEAR_TARGET_RATIO,
                'waste' => $waste,
                'stock_qty' => $stock,
                'status' => $status,
                'status_label' => $statusLabel,
                'status_reason' => $statusReason,
                'last_spend_date' => $row->last_spend_date
                    ? Carbon::parse((string) $row->last_spend_date)->toDateString()
                    : null,
            ];
        }

        usort($scorecard, static function (array $a, array $b): int {
            $rank = ['red' => 0, 'yellow' => 1, 'green' => 2];

            return ($rank[$a['status']] ?? 9) <=> ($rank[$b['status']] ?? 9)
                ?: ($b['cost'] <=> $a['cost']);
        });

        $avgMargin = $marginRates === []
            ? $this->defaultMarginRate()
            : array_sum($marginRates) / count($marginRates);
        $catalogTargets = $this->resolveTargets($avgMargin, $share, 'medium');
        $catalogTargetRoas = $catalogTargets['target_roas'];

        $blendedRoas = $totalCost > 0 ? $totalAttr / $totalCost : 0.0;
        $tacos = $gmvTotal > 0 ? $totalCost / $gmvTotal : 0.0;

        return [
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'days' => $days,
            ],
            'catalog_target_roas' => round($catalogTargetRoas, 2),
            'catalog_target_acos' => round($catalogTargetRoas > 0 ? 1 / $catalogTargetRoas : 1, 4),
            'ads_budget_share_of_margin' => $share,
            'avg_margin_rate' => round($avgMargin, 4),
            'near_target_ratio' => self::NEAR_TARGET_RATIO,
            'kpis' => [
                'spend' => round($totalCost, 2),
                'attributed_revenue' => round($totalAttr, 2),
                'blended_roas' => round($blendedRoas, 2),
                'tacos' => round($tacos, 4),
                'gmv' => round($gmvTotal, 2),
                'waste_spend' => round($wasteSpend, 2),
                'items' => count($scorecard),
            ],
            'scorecard' => $scorecard,
            'traffic_lights' => $lights,
        ];
    }

    /**
     * Target ROAS for a single item (or catalog default).
     */
    public function suggestedTargetRoas(int $workspaceId, ?string $mlItemId = null): float
    {
        $share = max(0.05, min(0.9, (float) config('ads.ads_budget_share_of_margin', 0.35)));
        $end = now()->startOfDay();
        $start = $end->copy()->subDays(29);

        if ($mlItemId) {
            $meta = $this->marginMetaByItem($workspaceId, [$mlItemId], $start, $end);
            $entry = $meta[$mlItemId] ?? [
                'margin_rate' => $this->defaultMarginRate(),
                'confidence' => 'low',
            ];
            $targets = $this->resolveTargets(
                (float) $entry['margin_rate'],
                $share,
                (string) $entry['confidence'],
            );

            return $targets['target_roas'];
        }

        $card = $this->scorecard($workspaceId, 30);

        return (float) $card['catalog_target_roas'];
    }

    public function targetRoasFromMargin(float $marginRate, ?float $share = null): float
    {
        $share ??= max(0.05, min(0.9, (float) config('ads.ads_budget_share_of_margin', 0.35)));
        $marginRate = max(0.02, min(0.95, $marginRate));
        $maxAcos = max(0.02, $marginRate * $share);

        return max(1.0, min(self::TARGET_ROAS_CAP, 1 / $maxAcos));
    }

    /**
     * Effective semaphore target: never use the 35x ceiling alone when margin data is weak.
     *
     * @return array{
     *     target_roas: float,
     *     target_roas_raw: float,
     *     target_roas_source: string,
     *     target_unreliable: bool
     * }
     */
    public function resolveTargets(float $marginRate, float $share, string $confidence): array
    {
        $raw = $this->targetRoasFromMargin($marginRate, $share);
        $hitCap = $raw >= self::TARGET_ROAS_CAP - 0.01;
        $unreliable = $confidence === 'low' || $hitCap;

        if ($unreliable) {
            $effective = $this->targetRoasFromMargin($this->defaultMarginRate(), $share);
            $source = $confidence === 'low' ? 'margen_default' : 'margen_default';
        } else {
            $effective = $raw;
            $source = $confidence === 'medium' ? 'catalog' : 'margen_real';
        }

        return [
            'target_roas' => round($effective, 2),
            'target_roas_raw' => round($raw, 2),
            'target_roas_source' => $source,
            'target_unreliable' => $unreliable,
        ];
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'red' => 'Actuar',
            'yellow' => 'Cuidado',
            default => 'Bien',
        };
    }

    /**
     * @param  list<string>  $itemIds
     * @return array<string, array{margin_rate: float, confidence: string}>
     */
    private function marginMetaByItem(int $workspaceId, array $itemIds, Carbon $start, Carbon $end): array
    {
        if ($itemIds === []) {
            return [];
        }

        $rows = OrderLine::query()
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->leftJoin('profit_snapshots', function ($join): void {
                $join->on('profit_snapshots.order_id', '=', 'orders.id')
                    ->where('profit_snapshots.stage', '=', 'expected');
            })
            ->where('order_lines.workspace_id', $workspaceId)
            ->whereIn('order_lines.external_item_id', $itemIds)
            ->whereBetween('orders.ordered_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->selectRaw('
                order_lines.external_item_id as ml_item_id,
                COALESCE(SUM(order_lines.line_total_amount), 0) as revenue,
                COALESCE(SUM(profit_snapshots.profit_amount * (
                    CASE WHEN orders.total_amount > 0
                        THEN order_lines.line_total_amount / orders.total_amount
                        ELSE 0 END
                )), 0) as profit,
                COUNT(DISTINCT profit_snapshots.id) as snapshot_count
            ')
            ->groupBy('order_lines.external_item_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $revenue = (float) $row->revenue;
            if ($revenue <= 0) {
                continue;
            }
            $profit = (float) $row->profit;
            $snapshots = (int) $row->snapshot_count;
            // Fallback when profit snapshots missing: assume default margin + low confidence.
            if ($profit == 0.0 || $snapshots === 0) {
                $out[(string) $row->ml_item_id] = [
                    'margin_rate' => $this->defaultMarginRate(),
                    'confidence' => 'low',
                ];
                continue;
            }
            $out[(string) $row->ml_item_id] = [
                'margin_rate' => max(0.02, min(0.95, $profit / $revenue)),
                'confidence' => $snapshots >= 3 ? 'high' : 'medium',
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $itemIds
     * @return array<string, int|null>
     */
    private function stockByItem(int $workspaceId, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $listings = ChannelListing::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('external_item_id', $itemIds)
            ->with(['variants' => fn ($q) => $q->select('id', 'channel_listing_id', 'available_quantity')])
            ->get(['id', 'external_item_id']);

        $out = [];
        foreach ($listings as $listing) {
            $qty = $listing->variants->sum(fn (ChannelListingVariant $v) => (int) ($v->available_quantity ?? 0));
            $out[(string) $listing->external_item_id] = $qty;
        }

        return $out;
    }

    /**
     * @param  list<string>  $itemIds
     * @return array<string, array{title: ?string, permalink: ?string, product_id: ?int}>
     */
    private function listingsByItem(int $workspaceId, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $listings = ChannelListing::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('external_item_id', $itemIds)
            ->get(['external_item_id', 'title', 'permalink', 'product_id']);

        $out = [];
        foreach ($listings as $listing) {
            $key = (string) $listing->external_item_id;
            if (isset($out[$key]) && ($out[$key]['title'] ?? null)) {
                continue;
            }
            $out[$key] = [
                'title' => $listing->title ? (string) $listing->title : null,
                'permalink' => $listing->permalink ? (string) $listing->permalink : null,
                'product_id' => $listing->product_id ? (int) $listing->product_id : null,
            ];
        }

        return $out;
    }

    private function workspaceGmv(int $workspaceId, Carbon $start, Carbon $end): float
    {
        return (float) Order::query()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('ordered_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->whereNotIn('status', ['cancelled', 'canceled'])
            ->sum('total_amount');
    }

    private function defaultMarginRate(): float
    {
        // Conservative LATAM electronics/general goods default when P&L incomplete.
        return 0.25;
    }

    private function trafficLight(
        float $roas,
        float $targetRoas,
        bool $waste,
        ?int $stock,
        float $cost,
        int $clicks,
    ): string {
        $minSpend = (float) config('ads.guardrails.min_spend_for_waste_pause', 50);
        if ($stock === 0) {
            return 'red';
        }
        if ($waste && $cost >= $minSpend) {
            return 'red';
        }
        if ($targetRoas > 0 && $clicks >= (int) config('ads.guardrails.min_clicks_for_roas_action', 15)) {
            if ($roas < self::NEAR_TARGET_RATIO * $targetRoas) {
                return 'red';
            }
            if ($roas < $targetRoas) {
                return 'yellow';
            }
        }
        if ($waste && $cost > 0) {
            return 'yellow';
        }

        return 'green';
    }

    private function statusReason(
        string $status,
        bool $waste,
        ?int $stock,
        float $cost,
        int $clicks,
        float $roas,
        float $targetRoas,
        bool $targetUnreliable,
    ): string {
        $minSpend = (float) config('ads.guardrails.min_spend_for_waste_pause', 50);
        $minClicks = (int) config('ads.guardrails.min_clicks_for_roas_action', 15);

        if ($stock === 0) {
            return 'Sin stock: los ads pueden seguir gastando sin poder vender.';
        }
        if ($waste && $cost >= $minSpend) {
            return 'Hay gasto en ads pero ML no atribuyó ventas en la ventana.';
        }
        if ($waste && $cost > 0) {
            return 'Hay algo de gasto sin ventas atribuidas; todavía no es crítico.';
        }
        if ($clicks < $minClicks) {
            return 'Pocos clics: aún no juzgamos el ROAS con confianza.';
        }
        if ($targetUnreliable && $status === 'green') {
            return 'La meta es provisional (faltan datos de ganancia); por ahora no hay señal fuerte de problema.';
        }
        if ($status === 'red') {
            return sprintf(
                'El ROAS (%.2fx) está muy por debajo de la meta para cuidar tu ganancia (%.2fx).',
                $roas,
                $targetRoas,
            );
        }
        if ($status === 'yellow') {
            return sprintf(
                'El ROAS (%.2fx) está por debajo de la meta (%.2fx); conviene vigilar o bajar presión.',
                $roas,
                $targetRoas,
            );
        }

        return 'La publicidad rinde lo necesario según tu margen estimado.';
    }
}
