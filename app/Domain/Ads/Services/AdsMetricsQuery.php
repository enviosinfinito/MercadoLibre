<?php

namespace App\Domain\Ads\Services;

use App\Domain\Returns\Support\ReturnPeriodResolver;
use App\Models\AdCampaign;
use App\Models\AdSpendDaily;
use App\Models\ChannelListing;
use App\Models\FinancialEvent;
use App\Models\OrderLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AdsMetricsQuery
{
    public function __construct(
        private readonly ReturnPeriodResolver $periodResolver,
    ) {}

    /**
     * @param  array{
     *     period?: string|null,
     *     from?: string|null,
     *     to?: string|null,
     *     connection_ids?: list<int>|null,
     *     campaign_ids?: list<int>|null,
     *     advertiser_ids?: list<int>|null,
     *     item_ids?: list<string>|null,
     *     product_ids?: list<int>|null,
     *     group_by?: string
     * }  $filters
     * @return array{
     *     period: array<string, mixed>,
     *     kpis: array<string, mixed>,
     *     series: list<array<string, mixed>>,
     *     breakdown: list<array<string, mixed>>,
     *     group_by: string,
     *     filters: array<string, mixed>,
     *     campaigns: list<array<string, mixed>>,
     *     has_data: bool
     * }
     */
    public function execute(int $workspaceId, array $filters = []): array
    {
        $period = $this->periodResolver->resolve(
            $filters['period'] ?? 'last_30_days',
            $filters['from'] ?? null,
            $filters['to'] ?? null,
        );

        $groupBy = $this->normalizeGroupBy((string) ($filters['group_by'] ?? 'day'));
        $connectionIds = $this->intList($filters['connection_ids'] ?? null);
        $campaignIds = $this->intList($filters['campaign_ids'] ?? null);
        $advertiserIds = $this->intList($filters['advertiser_ids'] ?? null);
        $itemIds = $this->stringList($filters['item_ids'] ?? null);
        $productIds = $this->intList($filters['product_ids'] ?? null);

        if ($productIds !== []) {
            $fromProducts = $this->mlItemIdsForProducts($workspaceId, $productIds);
            $itemIds = $itemIds === []
                ? $fromProducts
                : array_values(array_intersect($itemIds, $fromProducts));
            if ($itemIds === []) {
                return $this->emptyResult($period, $groupBy, $filters, $connectionIds, $campaignIds, $advertiserIds, $itemIds, $productIds);
            }
        }

        $base = AdSpendDaily::query()
            ->where('ad_spend_daily.workspace_id', $workspaceId)
            ->whereDate('ad_spend_daily.date', '>=', $period['start']->toDateString())
            ->whereDate('ad_spend_daily.date', '<=', $period['end']->toDateString());

        if ($connectionIds !== []) {
            $base->whereIn('ad_spend_daily.connection_id', $connectionIds);
        }
        if ($campaignIds !== []) {
            $base->whereIn('ad_spend_daily.ad_campaign_id', $campaignIds);
        }
        if ($advertiserIds !== []) {
            $base->whereIn('ad_spend_daily.ad_advertiser_id', $advertiserIds);
        }
        if ($itemIds !== []) {
            $base->whereIn('ad_spend_daily.ml_item_id', $itemIds);
        }

        $aggregates = (clone $base)
            ->selectRaw('
                COALESCE(SUM(ad_spend_daily.cost), 0) as cost,
                COALESCE(SUM(ad_spend_daily.clicks), 0) as clicks,
                COALESCE(SUM(ad_spend_daily.prints), 0) as prints,
                COALESCE(SUM(ad_spend_daily.direct_amount), 0) as direct_amount,
                COALESCE(SUM(ad_spend_daily.indirect_amount), 0) as indirect_amount,
                COALESCE(SUM(ad_spend_daily.total_amount), 0) as total_amount,
                COALESCE(SUM(ad_spend_daily.direct_units_quantity), 0) as direct_units,
                COALESCE(SUM(ad_spend_daily.indirect_units_quantity), 0) as indirect_units,
                COALESCE(SUM(ad_spend_daily.advertising_items_quantity), 0) as advertising_items,
                COALESCE(SUM(ad_spend_daily.organic_units_quantity), 0) as organic_units,
                COALESCE(SUM(ad_spend_daily.organic_units_amount), 0) as organic_amount,
                COALESCE(SUM(ad_spend_daily.organic_items_quantity), 0) as organic_items,
                COALESCE(SUM(ad_spend_daily.units_quantity), 0) as units_quantity,
                COUNT(*) as row_count
            ')
            ->first();

        $cost = (float) ($aggregates->cost ?? 0);
        $clicks = (int) ($aggregates->clicks ?? 0);
        $prints = (int) ($aggregates->prints ?? 0);
        $directAmount = (float) ($aggregates->direct_amount ?? 0);
        $indirectAmount = (float) ($aggregates->indirect_amount ?? 0);
        $attributedRevenue = (float) ($aggregates->total_amount ?? 0);
        if ($attributedRevenue <= 0) {
            $attributedRevenue = $directAmount + $indirectAmount;
        }
        $directUnits = (float) ($aggregates->direct_units ?? 0);
        $indirectUnits = (float) ($aggregates->indirect_units ?? 0);
        $attributedUnits = $directUnits + $indirectUnits;

        $localGmv = $this->localGmv(
            $workspaceId,
            $period['start'],
            $period['end'],
            $connectionIds,
            $itemIds,
        );

        $pnlAds = $this->pnlAdsAmount(
            $workspaceId,
            $period['start'],
            $period['end'],
            $connectionIds,
            $itemIds,
        );

        $cpc = $clicks > 0 ? $cost / $clicks : null;
        $ctr = $prints > 0 ? $clicks / $prints : null;
        $roas = $cost > 0 ? $attributedRevenue / $cost : null;
        $acos = $attributedRevenue > 0 ? $cost / $attributedRevenue : null;
        $tacos = $localGmv > 0 ? $cost / $localGmv : null;
        // Waste: spend with no ML-attributed units in the window (platform attribution purity).
        $wasteSpend = ($cost > 0 && $attributedUnits <= 0) ? $cost : 0.0;

        $series = $this->buildSeries((clone $base), 'day');
        $breakdown = $this->buildBreakdown((clone $base), $groupBy, $workspaceId);

        $campaigns = AdCampaign::query()
            ->where('workspace_id', $workspaceId)
            ->when($connectionIds !== [], fn ($q) => $q->whereIn('connection_id', $connectionIds))
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'external_campaign_id', 'status', 'connection_id'])
            ->map(fn (AdCampaign $c) => [
                'id' => $c->id,
                'name' => $c->name ?: $c->external_campaign_id,
                'external_campaign_id' => $c->external_campaign_id,
                'status' => $c->status,
                'connection_id' => $c->connection_id,
            ])
            ->values()
            ->all();

        return [
            'period' => [
                'key' => $period['key'],
                'label' => $period['label'],
                'start' => $period['start']->toDateString(),
                'end' => $period['end']->toDateString(),
            ],
            'kpis' => [
                'cost' => round($cost, 6),
                'clicks' => $clicks,
                'prints' => $prints,
                'cpc' => $cpc !== null ? round($cpc, 6) : null,
                'ctr' => $ctr !== null ? round($ctr, 8) : null,
                'attributed_revenue' => round($attributedRevenue, 6),
                'direct_amount' => round($directAmount, 6),
                'indirect_amount' => round($indirectAmount, 6),
                'roas' => $roas !== null ? round($roas, 4) : null,
                'acos' => $acos !== null ? round($acos, 6) : null,
                'tacos' => $tacos !== null ? round($tacos, 6) : null,
                'blended_acos' => $tacos !== null ? round($tacos, 6) : null,
                'local_gmv' => round($localGmv, 6),
                'pnl_ads' => round($pnlAds, 6),
                'pnl_ads_delta' => round($cost - $pnlAds, 6),
                'direct_units' => $directUnits,
                'indirect_units' => $indirectUnits,
                'advertising_items' => (float) ($aggregates->advertising_items ?? 0),
                'organic_units' => (float) ($aggregates->organic_units ?? 0),
                'organic_amount' => round((float) ($aggregates->organic_amount ?? 0), 6),
                'organic_items' => (float) ($aggregates->organic_items ?? 0),
                'units_quantity' => (float) ($aggregates->units_quantity ?? 0),
                'waste_spend' => round($wasteSpend, 6),
                'currency' => 'MXN',
            ],
            'series' => $series,
            'breakdown' => $breakdown,
            'group_by' => $groupBy,
            'filters' => [
                'period' => $filters['period'] ?? 'last_30_days',
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'connection_ids' => $connectionIds,
                'campaign_ids' => $campaignIds,
                'advertiser_ids' => $advertiserIds,
                'item_ids' => $itemIds,
                'product_ids' => $productIds,
                'group_by' => $groupBy,
            ],
            'campaigns' => $campaigns,
            'has_data' => (int) ($aggregates->row_count ?? 0) > 0,
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\AdSpendDaily>  $query
     * @return list<array<string, mixed>>
     */
    private function buildSeries($query, string $groupBy): array
    {
        $rows = $query
            ->selectRaw('
                ad_spend_daily.date as bucket,
                SUM(ad_spend_daily.cost) as cost,
                SUM(ad_spend_daily.total_amount) as attributed_revenue,
                SUM(ad_spend_daily.clicks) as clicks,
                SUM(ad_spend_daily.prints) as prints,
                SUM(ad_spend_daily.direct_units_quantity + ad_spend_daily.indirect_units_quantity) as units
            ')
            ->groupBy('ad_spend_daily.date')
            ->orderBy('ad_spend_daily.date')
            ->get();

        return $rows->map(function ($row) {
            $cost = (float) $row->cost;
            $revenue = (float) $row->attributed_revenue;
            $clicks = (int) $row->clicks;
            $prints = (int) $row->prints;

            return [
                'bucket' => Carbon::parse($row->bucket)->toDateString(),
                'label' => Carbon::parse($row->bucket)->toDateString(),
                'cost' => round($cost, 6),
                'attributed_revenue' => round($revenue, 6),
                'clicks' => $clicks,
                'prints' => $prints,
                'units' => round((float) $row->units, 2),
                'cpc' => $clicks > 0 ? round($cost / $clicks, 6) : null,
                'ctr' => $prints > 0 ? round($clicks / $prints, 8) : null,
                'roas' => $cost > 0 ? round($revenue / $cost, 4) : null,
                'acos' => $revenue > 0 ? round($cost / $revenue, 6) : null,
            ];
        })->values()->all();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\AdSpendDaily>  $query
     * @return list<array<string, mixed>>
     */
    private function buildBreakdown($query, string $groupBy, int $workspaceId): array
    {
        if (in_array($groupBy, ['day', 'week', 'month'], true)) {
            $expr = match ($groupBy) {
                'week' => "DATE_FORMAT(ad_spend_daily.date, '%x-W%v')",
                'month' => "DATE_FORMAT(ad_spend_daily.date, '%Y-%m')",
                default => 'ad_spend_daily.date',
            };

            // SQLite-friendly fallbacks for tests.
            if (DB::getDriverName() === 'sqlite') {
                $expr = match ($groupBy) {
                    'week' => "strftime('%Y-%W', ad_spend_daily.date)",
                    'month' => "strftime('%Y-%m', ad_spend_daily.date)",
                    default => 'ad_spend_daily.date',
                };
            }

            $rows = $query
                ->selectRaw("{$expr} as bucket_key, SUM(ad_spend_daily.cost) as cost, SUM(ad_spend_daily.clicks) as clicks, SUM(ad_spend_daily.prints) as prints, SUM(ad_spend_daily.total_amount) as attributed_revenue")
                ->groupBy('bucket_key')
                ->orderByDesc('cost')
                ->limit(100)
                ->get();

            return $rows->map(fn ($row) => $this->breakdownRow(
                (string) $row->bucket_key,
                (string) $row->bucket_key,
                $row,
            ))->values()->all();
        }

        if ($groupBy === 'campaign') {
            $rows = $query
                ->leftJoin('ad_campaigns', 'ad_campaigns.id', '=', 'ad_spend_daily.ad_campaign_id')
                ->selectRaw("
                    COALESCE(ad_spend_daily.ad_campaign_id, 0) as bucket_id,
                    COALESCE(ad_campaigns.name, ad_spend_daily.external_campaign_id, 'Sin campaña') as bucket_label,
                    SUM(ad_spend_daily.cost) as cost,
                    SUM(ad_spend_daily.clicks) as clicks,
                    SUM(ad_spend_daily.prints) as prints,
                    SUM(ad_spend_daily.total_amount) as attributed_revenue
                ")
                ->groupByRaw('COALESCE(ad_spend_daily.ad_campaign_id, 0), COALESCE(ad_campaigns.name, ad_spend_daily.external_campaign_id, \'Sin campaña\')')
                ->orderByDesc('cost')
                ->limit(100)
                ->get();

            return $rows->map(fn ($row) => $this->breakdownRow(
                (string) $row->bucket_id,
                (string) $row->bucket_label,
                $row,
                ['campaign_id' => (int) $row->bucket_id ?: null],
            ))->values()->all();
        }

        if ($groupBy === 'connection') {
            $rows = $query
                ->leftJoin('connections', 'connections.id', '=', 'ad_spend_daily.connection_id')
                ->selectRaw("
                    ad_spend_daily.connection_id as bucket_id,
                    COALESCE(connections.display_name, connections.external_user_id, 'connection') as bucket_label,
                    SUM(ad_spend_daily.cost) as cost,
                    SUM(ad_spend_daily.clicks) as clicks,
                    SUM(ad_spend_daily.prints) as prints,
                    SUM(ad_spend_daily.total_amount) as attributed_revenue
                ")
                ->groupBy('ad_spend_daily.connection_id', 'connections.display_name', 'connections.external_user_id')
                ->orderByDesc('cost')
                ->limit(100)
                ->get();

            return $rows->map(fn ($row) => $this->breakdownRow(
                (string) $row->bucket_id,
                (string) ($row->bucket_label === 'connection' ? 'Conexión #'.$row->bucket_id : $row->bucket_label),
                $row,
                ['connection_id' => (int) $row->bucket_id],
            ))->values()->all();
        }

        if ($groupBy === 'product') {
            $itemRows = $query
                ->selectRaw('ad_spend_daily.ml_item_id as bucket_key, SUM(ad_spend_daily.cost) as cost, SUM(ad_spend_daily.clicks) as clicks, SUM(ad_spend_daily.prints) as prints, SUM(ad_spend_daily.total_amount) as attributed_revenue')
                ->groupBy('ad_spend_daily.ml_item_id')
                ->orderByDesc('cost')
                ->limit(200)
                ->get();

            $itemIds = $itemRows->pluck('bucket_key')->filter()->values()->all();
            $productMap = $this->productLabelsForItems($workspaceId, $itemIds);

            /** @var Collection<string, array<string, mixed>> $byProduct */
            $byProduct = collect();
            foreach ($itemRows as $row) {
                $itemId = (string) $row->bucket_key;
                $product = $productMap[$itemId] ?? null;
                $key = $product ? 'p:'.$product['id'] : 'i:'.$itemId;
                $label = $product['name'] ?? $itemId;
                $existing = $byProduct->get($key);
                if ($existing === null) {
                    $byProduct->put($key, [
                        'key' => $key,
                        'label' => $label,
                        'cost' => (float) $row->cost,
                        'clicks' => (int) $row->clicks,
                        'prints' => (int) $row->prints,
                        'attributed_revenue' => (float) $row->attributed_revenue,
                        'product_id' => $product['id'] ?? null,
                    ]);
                } else {
                    $existing['cost'] += (float) $row->cost;
                    $existing['clicks'] += (int) $row->clicks;
                    $existing['prints'] += (int) $row->prints;
                    $existing['attributed_revenue'] += (float) $row->attributed_revenue;
                    $byProduct->put($key, $existing);
                }
            }

            return $byProduct
                ->sortByDesc('cost')
                ->take(100)
                ->map(fn (array $row) => [
                    'key' => $row['key'],
                    'label' => $row['label'],
                    'cost' => round($row['cost'], 6),
                    'clicks' => $row['clicks'],
                    'prints' => $row['prints'],
                    'attributed_revenue' => round($row['attributed_revenue'], 6),
                    'roas' => $row['cost'] > 0 ? round($row['attributed_revenue'] / $row['cost'], 4) : null,
                    'acos' => $row['attributed_revenue'] > 0 ? round($row['cost'] / $row['attributed_revenue'], 6) : null,
                    'meta' => ['product_id' => $row['product_id']],
                ])
                ->values()
                ->all();
        }

        // default: item
        $rows = $query
            ->selectRaw('ad_spend_daily.ml_item_id as bucket_key, SUM(ad_spend_daily.cost) as cost, SUM(ad_spend_daily.clicks) as clicks, SUM(ad_spend_daily.prints) as prints, SUM(ad_spend_daily.total_amount) as attributed_revenue')
            ->groupBy('ad_spend_daily.ml_item_id')
            ->orderByDesc('cost')
            ->limit(100)
            ->get();

        $labels = $this->listingTitles($workspaceId, $rows->pluck('bucket_key')->all());

        return $rows->map(fn ($row) => $this->breakdownRow(
            (string) $row->bucket_key,
            $labels[(string) $row->bucket_key] ?? (string) $row->bucket_key,
            $row,
            ['item_id' => (string) $row->bucket_key],
        ))->values()->all();
    }

    /**
     * @param  object{cost: mixed, clicks: mixed, prints: mixed, attributed_revenue: mixed}  $row
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function breakdownRow(string $key, string $label, object $row, array $meta = []): array
    {
        $cost = (float) $row->cost;
        $revenue = (float) $row->attributed_revenue;

        return [
            'key' => $key,
            'label' => $label,
            'cost' => round($cost, 6),
            'clicks' => (int) $row->clicks,
            'prints' => (int) $row->prints,
            'attributed_revenue' => round($revenue, 6),
            'roas' => $cost > 0 ? round($revenue / $cost, 4) : null,
            'acos' => $revenue > 0 ? round($cost / $revenue, 6) : null,
            'meta' => $meta,
        ];
    }

    /**
     * @param  list<int>  $connectionIds
     * @param  list<string>  $itemIds
     */
    private function localGmv(
        int $workspaceId,
        Carbon $start,
        Carbon $end,
        array $connectionIds,
        array $itemIds,
    ): float {
        $q = OrderLine::query()
            ->where('order_lines.workspace_id', $workspaceId)
            ->whereHas('order', function ($order) use ($start, $end, $connectionIds) {
                $order->whereBetween('ordered_at', [$start, $end]);
                if ($connectionIds !== []) {
                    $order->whereIn('connection_id', $connectionIds);
                }
            });

        if ($itemIds !== []) {
            $q->whereIn('external_item_id', $itemIds);
        }

        return (float) $q->sum('line_total_amount');
    }

    /**
     * @param  list<int>  $connectionIds
     * @param  list<string>  $itemIds
     */
    private function pnlAdsAmount(
        int $workspaceId,
        Carbon $start,
        Carbon $end,
        array $connectionIds,
        array $itemIds,
    ): float {
        $q = FinancialEvent::query()
            ->where('workspace_id', $workspaceId)
            ->where('stage', 'expected')
            ->where('event_type', 'expected_advertising')
            ->whereBetween('occurred_at', [$start, $end]);

        if ($connectionIds !== []) {
            $q->whereIn('connection_id', $connectionIds);
        }

        if ($itemIds !== []) {
            $q->where(function ($inner) use ($itemIds) {
                foreach ($itemIds as $itemId) {
                    $inner->orWhere('provenance->ml_item_id', $itemId);
                }
            });
        }

        $sum = (float) $q->sum(DB::raw('ABS(amount)'));

        return $sum;
    }

    /**
     * @param  list<int>  $productIds
     * @return list<string>
     */
    private function mlItemIdsForProducts(int $workspaceId, array $productIds): array
    {
        return ChannelListing::query()
            ->where('workspace_id', $workspaceId)
            ->where(function ($q) use ($productIds) {
                $q->whereIn('product_id', $productIds)
                    ->orWhereHas(
                        'variants',
                        fn ($v) => $v->whereHas(
                            'variant',
                            fn ($canonical) => $canonical->whereIn('product_id', $productIds),
                        ),
                    );
            })
            ->whereNotNull('external_item_id')
            ->pluck('external_item_id')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $itemIds
     * @return array<string, array{id: int, name: string}>
     */
    private function productLabelsForItems(int $workspaceId, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $listings = ChannelListing::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('external_item_id', $itemIds)
            ->with(['product:id,name', 'variants.variant:id,product_id'])
            ->get();

        $map = [];
        foreach ($listings as $listing) {
            $itemId = (string) $listing->external_item_id;
            $productId = $listing->product_id;
            $name = $listing->product?->name;
            if ($productId === null) {
                $variant = $listing->variants->first(fn ($v) => $v->variant?->product_id);
                $productId = $variant?->variant?->product_id;
            }
            if ($productId === null) {
                continue;
            }
            $map[$itemId] = [
                'id' => (int) $productId,
                'name' => $name ?: (string) $productId,
            ];
        }

        return $map;
    }

    /**
     * @param  list<string>  $itemIds
     * @return array<string, string>
     */
    private function listingTitles(int $workspaceId, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return ChannelListing::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('external_item_id', $itemIds)
            ->pluck('title', 'external_item_id')
            ->mapWithKeys(fn ($title, $id) => [(string) $id => (string) ($title ?: $id)])
            ->all();
    }

    private function normalizeGroupBy(string $groupBy): string
    {
        return in_array($groupBy, ['day', 'week', 'month', 'campaign', 'item', 'product', 'connection'], true)
            ? $groupBy
            : 'day';
    }

    /**
     * @param  list<int>|null  $values
     * @return list<int>
     */
    private function intList(?array $values): array
    {
        if ($values === null) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $values), fn ($v) => $v > 0)));
    }

    /**
     * @param  list<string>|null  $values
     * @return list<string>
     */
    private function stringList(?array $values): array
    {
        if ($values === null) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($v) => trim((string) $v),
            $values,
        ), static fn ($v) => $v !== '')));
    }

    /**
     * @param  array<string, mixed>  $period
     * @param  array<string, mixed>  $filters
     * @param  list<int>  $connectionIds
     * @param  list<int>  $campaignIds
     * @param  list<int>  $advertiserIds
     * @param  list<string>  $itemIds
     * @param  list<int>  $productIds
     * @return array<string, mixed>
     */
    private function emptyResult(
        array $period,
        string $groupBy,
        array $filters,
        array $connectionIds,
        array $campaignIds,
        array $advertiserIds,
        array $itemIds,
        array $productIds,
    ): array {
        return [
            'period' => [
                'key' => $period['key'],
                'label' => $period['label'],
                'start' => $period['start']->toDateString(),
                'end' => $period['end']->toDateString(),
            ],
            'kpis' => [
                'cost' => 0.0,
                'clicks' => 0,
                'prints' => 0,
                'cpc' => null,
                'ctr' => null,
                'attributed_revenue' => 0.0,
                'direct_amount' => 0.0,
                'indirect_amount' => 0.0,
                'roas' => null,
                'acos' => null,
                'tacos' => null,
                'blended_acos' => null,
                'local_gmv' => 0.0,
                'pnl_ads' => 0.0,
                'pnl_ads_delta' => 0.0,
                'direct_units' => 0.0,
                'indirect_units' => 0.0,
                'advertising_items' => 0.0,
                'organic_units' => 0.0,
                'organic_amount' => 0.0,
                'organic_items' => 0.0,
                'units_quantity' => 0.0,
                'waste_spend' => 0.0,
                'currency' => 'MXN',
            ],
            'series' => [],
            'breakdown' => [],
            'group_by' => $groupBy,
            'filters' => [
                'period' => $filters['period'] ?? 'last_30_days',
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'connection_ids' => $connectionIds,
                'campaign_ids' => $campaignIds,
                'advertiser_ids' => $advertiserIds,
                'item_ids' => $itemIds,
                'product_ids' => $productIds,
                'group_by' => $groupBy,
            ],
            'campaigns' => [],
            'has_data' => false,
        ];
    }
}
