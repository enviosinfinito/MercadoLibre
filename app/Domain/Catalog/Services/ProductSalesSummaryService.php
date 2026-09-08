<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Inventory\Services\StockDepletionForecastService;
use App\Domain\Inventory\Support\VariantSalesAttribution;
use App\Domain\Returns\Support\ReturnPeriodResolver;
use App\Domain\Sales\Support\OrderSalesClassification;
use App\Models\ChannelListing;
use App\Models\ChannelListingVariant;
use App\Models\CostSnapshot;
use App\Models\InventoryBalance;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\ProfitSnapshot;
use App\Models\Variant;
use Illuminate\Support\Carbon;

final class ProductSalesSummaryService
{
    public function __construct(
        private readonly ReturnPeriodResolver $periodResolver,
        private readonly StockDepletionForecastService $depletionForecast,
    ) {}

    /**
     * Lifetime successful units sold, keyed for list UIs.
     *
     * @param  list<int>  $productIds
     * @param  list<string>  $mlItemIds
     * @return array{by_product: array<int, int>, by_ml_item: array<string, int>}
     */
    public function unitsSoldByKeys(int $workspaceId, array $productIds, array $mlItemIds = []): array
    {
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            fn (int $id) => $id > 0,
        )));
        $mlItemIds = array_values(array_unique(array_filter(
            $mlItemIds,
            fn ($id) => is_string($id) && $id !== '',
        )));

        $byProduct = array_fill_keys($productIds, 0);
        $byMl = array_fill_keys($mlItemIds, 0);

        if ($productIds === [] && $mlItemIds === []) {
            return ['by_product' => $byProduct, 'by_ml_item' => $byMl];
        }

        $externalByProduct = $this->productExternalItemIdsMap($workspaceId, $productIds);
        $externalToProducts = [];
        foreach ($externalByProduct as $productId => $externals) {
            foreach ($externals as $externalId) {
                $externalToProducts[$externalId][] = (int) $productId;
            }
        }

        $allExternals = array_values(array_unique(array_merge(
            array_keys($externalToProducts),
            $mlItemIds,
        )));

        $query = OrderLine::query()
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->leftJoin('variants', 'variants.id', '=', 'order_lines.variant_id')
            ->where('order_lines.workspace_id', $workspaceId)
            ->whereNotIn('orders.status', OrderSalesClassification::cancelledStatuses())
            ->where(function ($inner) {
                $inner->whereNull('orders.post_sale_outcome')
                    ->orWhereNotIn('orders.post_sale_outcome', OrderSalesClassification::reversedOutcomes());
            })
            ->where(function ($match) use ($productIds, $allExternals) {
                if ($productIds !== []) {
                    $match->whereIn('variants.product_id', $productIds);
                }
                if ($allExternals !== []) {
                    if ($productIds !== []) {
                        $match->orWhereIn('order_lines.external_item_id', $allExternals);
                    } else {
                        $match->whereIn('order_lines.external_item_id', $allExternals);
                    }
                }
            });

        $lines = $query->get([
            'order_lines.quantity',
            'order_lines.external_item_id',
            'variants.product_id as variant_product_id',
        ]);

        foreach ($lines as $line) {
            $qty = (int) round((float) $line->quantity);
            if ($qty === 0) {
                continue;
            }

            $countedForProduct = [];
            $variantProductId = $line->variant_product_id !== null ? (int) $line->variant_product_id : null;
            if ($variantProductId !== null && array_key_exists($variantProductId, $byProduct)) {
                $byProduct[$variantProductId] += $qty;
                $countedForProduct[$variantProductId] = true;
            }

            $externalId = is_string($line->external_item_id) ? $line->external_item_id : null;
            if ($externalId !== null && isset($externalToProducts[$externalId])) {
                foreach ($externalToProducts[$externalId] as $productId) {
                    if (isset($countedForProduct[$productId])) {
                        continue;
                    }
                    if (! array_key_exists($productId, $byProduct)) {
                        continue;
                    }
                    $byProduct[$productId] += $qty;
                    $countedForProduct[$productId] = true;
                }
            }

            if ($externalId !== null && array_key_exists($externalId, $byMl)) {
                $byMl[$externalId] += $qty;
            }
        }

        return ['by_product' => $byProduct, 'by_ml_item' => $byMl];
    }

    /**
     * @param  list<int>|null  $connectionIds
     * @return array{
     *     period: array{preset: string, from: string, to: string, label: string},
     *     summary: array<string, mixed>,
     *     stock: array<string, mixed>,
     *     series: list<array{date: string, units: int, sales: float}>,
     *     series_by_variant: list<array{variant_id: int, sku: string|null, units_total: int, points: list<array{date: string, units: int}>}>,
     *     stock_impact: array<string, mixed>,
     *     orders: list<array<string, mixed>>
     * }
     */
    public function forProduct(
        int $workspaceId,
        Product $product,
        string $periodPreset = 'all',
        ?array $connectionIds = null,
    ): array {
        $period = $this->resolvePeriod($periodPreset);

        $lines = $this->productLinesQuery(
            $workspaceId,
            (int) $product->id,
            $period['start'],
            $period['end'],
            $connectionIds,
        )
            ->select([
                'order_lines.id',
                'order_lines.order_id',
                'order_lines.quantity',
                'order_lines.line_total_amount',
                'order_lines.currency_code',
                'order_lines.variant_id',
                'order_lines.channel_listing_variant_id',
                'order_lines.external_item_id',
                'order_lines.external_variation_id',
                'orders.external_order_id',
                'orders.status',
                'orders.post_sale_outcome',
                'orders.ordered_at',
                'orders.buyer_external_id',
                'orders.currency_code as order_currency',
                'orders.connection_id',
                'connections.provider as connection_provider',
                'connections.display_name as connection_display_name',
                'connections.external_user_id as connection_external_user_id',
                'connections.color as connection_color',
            ])
            ->orderByDesc('orders.ordered_at')
            ->get();

        $unitsSold = (int) round((float) $lines->sum(fn ($l) => (float) $l->quantity));
        $grossSales = (float) $lines->sum(fn ($l) => (float) $l->line_total_amount);
        $ordersCount = $lines->pluck('order_id')->unique()->count();
        $currency = (string) ($lines->first()?->currency_code
            ?: $lines->first()?->order_currency
            ?: 'MXN');

        $attribution = $this->attributeProfit($workspaceId, $lines);
        $stock = $this->stockForProduct($workspaceId, (int) $product->id);
        $series = $this->dailySeries($lines, $period['start'], $period['end']);
        $seriesByVariant = $this->seriesByVariant(
            $workspaceId,
            $lines,
            $stock['variants'] ?? [],
            $period['start'],
            $period['end'],
        );
        $stockImpact = $this->stockImpact($lines, $stock['variants'] ?? [], $workspaceId, $unitsSold);
        $orders = $this->orderRows($lines, 25);

        return [
            'period' => [
                'preset' => $period['key'],
                'from' => $period['start']->toDateString(),
                'to' => $period['end']->toDateString(),
                'label' => $period['label'],
            ],
            'summary' => [
                'orders_count' => $ordersCount,
                'units_sold' => $unitsSold,
                'gross_sales' => round($grossSales, 2),
                'currency' => $currency,
                'attributed_fees' => round($attribution['fees'], 2),
                'attributed_taxes' => round($attribution['taxes'], 2),
                'attributed_cogs' => round($attribution['cogs'], 2),
                'attributed_profit' => round(
                    $grossSales - $attribution['fees'] - $attribution['taxes'] - $attribution['cogs'],
                    2,
                ),
                'incomplete_cogs' => $attribution['incomplete_cogs'],
                'incomplete_profit' => $attribution['incomplete_profit'],
            ],
            'stock' => $stock,
            'series' => $series,
            'series_by_variant' => $seriesByVariant,
            'stock_impact' => $stockImpact,
            'orders' => $orders,
        ];
    }

    /**
     * @return array{start: Carbon, end: Carbon, key: string, label: string}
     */
    private function resolvePeriod(string $preset): array
    {
        if ($preset === 'all') {
            $end = now()->copy()->timezone(config('app.timezone', 'UTC'))->endOfDay();
            $start = Carbon::create(2000, 1, 1, 0, 0, 0, $end->timezone)->startOfDay();

            return [
                'start' => $start,
                'end' => $end,
                'key' => 'all',
                'label' => 'Todo el historial',
            ];
        }

        $resolved = $this->periodResolver->resolve($preset);

        return [
            'start' => $resolved['start'],
            'end' => $resolved['end'],
            'key' => $resolved['key'],
            'label' => $resolved['label'],
        ];
    }

    /**
     * @param  list<int>|null  $connectionIds
     */
    private function productLinesQuery(
        int $workspaceId,
        int $productId,
        Carbon $start,
        Carbon $end,
        ?array $connectionIds,
    ) {
        $externalItemIds = $this->productExternalItemIds($workspaceId, $productId);

        $query = OrderLine::query()
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->leftJoin('variants', 'variants.id', '=', 'order_lines.variant_id')
            ->leftJoin('connections', 'connections.id', '=', 'orders.connection_id')
            ->where('order_lines.workspace_id', $workspaceId)
            ->where(function ($match) use ($productId, $externalItemIds) {
                $match->where('variants.product_id', $productId);
                if ($externalItemIds !== []) {
                    $match->orWhereIn('order_lines.external_item_id', $externalItemIds);
                }
            })
            ->whereBetween('orders.ordered_at', [$start, $end])
            ->whereNotIn('orders.status', OrderSalesClassification::cancelledStatuses())
            ->where(function ($inner) {
                $inner->whereNull('orders.post_sale_outcome')
                    ->orWhereNotIn('orders.post_sale_outcome', OrderSalesClassification::reversedOutcomes());
            });

        if ($connectionIds !== null && $connectionIds !== []) {
            $query->whereIn('orders.connection_id', $connectionIds);
        }

        return $query;
    }

    /**
     * External ML item ids linked to the product (direct listing or via matched variants).
     *
     * @return list<string>
     */
    private function productExternalItemIds(int $workspaceId, int $productId): array
    {
        return $this->productExternalItemIdsMap($workspaceId, [$productId])[$productId] ?? [];
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, list<string>>
     */
    private function productExternalItemIdsMap(int $workspaceId, array $productIds): array
    {
        $map = [];
        foreach ($productIds as $productId) {
            $map[(int) $productId] = [];
        }
        if ($productIds === []) {
            return $map;
        }

        $direct = ChannelListing::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('product_id', $productIds)
            ->whereNotNull('external_item_id')
            ->get(['product_id', 'external_item_id']);

        foreach ($direct as $listing) {
            $pid = (int) $listing->product_id;
            $ext = $listing->external_item_id;
            if (! is_string($ext) || $ext === '') {
                continue;
            }
            $map[$pid][] = $ext;
        }

        $viaVariants = ChannelListing::query()
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('external_item_id')
            ->whereHas(
                'variants',
                fn ($q) => $q->whereHas(
                    'variant',
                    fn ($canonical) => $canonical->whereIn('product_id', $productIds),
                ),
            )
            ->with(['variants.variant:id,product_id'])
            ->get(['id', 'external_item_id']);

        foreach ($viaVariants as $listing) {
            $ext = $listing->external_item_id;
            if (! is_string($ext) || $ext === '') {
                continue;
            }
            foreach ($listing->variants as $clv) {
                $pid = $clv->variant?->product_id;
                if ($pid === null || ! array_key_exists((int) $pid, $map)) {
                    continue;
                }
                $map[(int) $pid][] = $ext;
            }
        }

        foreach ($map as $pid => $externals) {
            $map[$pid] = array_values(array_unique($externals));
        }

        return $map;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $lines
     * @return array{fees: float, taxes: float, cogs: float, incomplete_cogs: bool, incomplete_profit: bool}
     */
    private function attributeProfit(int $workspaceId, $lines): array
    {
        if ($lines->isEmpty()) {
            return [
                'fees' => 0.0,
                'taxes' => 0.0,
                'cogs' => 0.0,
                'incomplete_cogs' => false,
                'incomplete_profit' => false,
            ];
        }

        $lineIds = $lines->pluck('id')->map(fn ($id) => (int) $id)->all();
        $cogsByLine = CostSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('order_line_id', $lineIds)
            ->pluck('total_cogs_reporting_amount', 'order_line_id');

        $cogs = 0.0;
        $missingCogs = 0;
        foreach ($lineIds as $lineId) {
            if (! $cogsByLine->has($lineId)) {
                $missingCogs++;
                continue;
            }
            $cogs += (float) $cogsByLine->get($lineId);
        }

        $productTotalByOrder = $lines
            ->groupBy('order_id')
            ->map(fn ($group) => (float) $group->sum(fn ($l) => (float) $l->line_total_amount));

        $orderIds = $productTotalByOrder->keys()->map(fn ($id) => (int) $id)->all();
        $snapshots = ProfitSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->where('stage', 'expected')
            ->whereIn('order_id', $orderIds)
            ->get()
            ->keyBy('order_id');

        $fees = 0.0;
        $taxes = 0.0;
        $missingProfit = 0;

        foreach ($productTotalByOrder as $orderId => $productRevenue) {
            $snapshot = $snapshots->get((int) $orderId);
            if ($snapshot === null) {
                $missingProfit++;
                continue;
            }

            $orderRevenue = (float) $snapshot->revenue_amount;
            if ($orderRevenue <= 0) {
                $missingProfit++;
                continue;
            }

            $share = min(1.0, max(0.0, $productRevenue / $orderRevenue));
            $fees += (float) $snapshot->fees_amount * $share;
            $taxTotal = (float) ($snapshot->payload['taxes_retention_total'] ?? 0);
            $taxes += $taxTotal * $share;
        }

        return [
            'fees' => $fees,
            'taxes' => $taxes,
            'cogs' => $cogs,
            'incomplete_cogs' => $missingCogs > 0,
            'incomplete_profit' => $missingProfit > 0,
        ];
    }

    /**
     * @return array{
     *     channel_stock: int,
     *     internal_stock: float,
     *     internal_available: float,
     *     low_stock: bool,
     *     stock_basis: 'channel'|'internal',
     *     forecast: array<string, mixed>,
     *     variants: list<array<string, mixed>>
     * }
     */
    private function stockForProduct(int $workspaceId, int $productId): array
    {
        $variants = Variant::query()
            ->where('workspace_id', $workspaceId)
            ->where('product_id', $productId)
            ->orderBy('sku')
            ->get(['id', 'sku', 'name']);

        $variantIds = $variants->pluck('id')->map(fn ($id) => (int) $id)->all();

        $channelByVariant = [];
        if ($variantIds !== []) {
            $channelRows = ChannelListingVariant::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('variant_id', $variantIds)
                ->selectRaw('variant_id, COALESCE(SUM(available_quantity), 0) as qty')
                ->groupBy('variant_id')
                ->get();

            foreach ($channelRows as $row) {
                $vid = (int) $row->variant_id;
                $channelByVariant[$vid] = (float) $row->qty;
            }

            // Variants with a CLV row but possibly zero qty still count as channel basis.
            $clvVariantIds = ChannelListingVariant::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('variant_id', $variantIds)
                ->distinct()
                ->pluck('variant_id');
            foreach ($clvVariantIds as $vid) {
                $vid = (int) $vid;
                if (! array_key_exists($vid, $channelByVariant)) {
                    $channelByVariant[$vid] = 0.0;
                }
            }
        }

        $internalByVariant = array_fill_keys($variantIds, 0.0);
        $onHandByVariant = array_fill_keys($variantIds, 0.0);

        if ($variantIds !== []) {
            $balances = InventoryBalance::query()
                ->where('inventory_balances.workspace_id', $workspaceId)
                ->whereHas('inventoryItem', fn ($q) => $q->whereIn('variant_id', $variantIds))
                ->with(['inventoryItem:id,variant_id'])
                ->get(['inventory_item_id', 'quantity_on_hand', 'quantity_reserved', 'quantity_available']);

            foreach ($balances as $balance) {
                $vid = (int) ($balance->inventoryItem?->variant_id ?? 0);
                if ($vid === 0 || ! array_key_exists($vid, $internalByVariant)) {
                    continue;
                }
                $onHandByVariant[$vid] += (float) $balance->quantity_on_hand;
                $internalByVariant[$vid] += (float) $balance->quantity_available;
            }
        }

        $variantRows = [];
        foreach ($variants as $variant) {
            $vid = (int) $variant->id;
            $variantRows[] = [
                'id' => $vid,
                'sku' => $variant->sku,
                'name' => $variant->name,
                'channel_stock' => array_key_exists($vid, $channelByVariant)
                    ? $channelByVariant[$vid]
                    : null,
                'internal_available' => $internalByVariant[$vid] ?? 0.0,
            ];
        }

        $bundle = $this->depletionForecast->forProductWithVariants(
            $workspaceId,
            $productId,
            $variantRows,
        );

        $channelStock = (int) round(array_sum($channelByVariant));
        $onHand = array_sum($onHandByVariant);
        $available = array_sum($internalByVariant);

        return [
            'channel_stock' => $channelStock,
            'internal_stock' => round($onHand, 2),
            'internal_available' => round($available, 2),
            'low_stock' => (bool) ($bundle['forecast']['low_stock'] ?? false),
            'stock_basis' => $bundle['stock_basis'],
            'forecast' => $bundle['forecast'],
            'aggregate_forecast' => $bundle['aggregate_forecast'] ?? null,
            'assortment' => $bundle['assortment'] ?? null,
            'variants' => $bundle['variants'],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $lines
     * @return list<array{date: string, units: int, sales: float}>
     */
    private function dailySeries($lines, Carbon $start, Carbon $end): array
    {
        $byDate = [];
        foreach ($lines as $line) {
            $date = Carbon::parse($line->ordered_at)->toDateString();
            if (! isset($byDate[$date])) {
                $byDate[$date] = ['date' => $date, 'units' => 0, 'sales' => 0.0];
            }
            $byDate[$date]['units'] += (int) round((float) $line->quantity);
            $byDate[$date]['sales'] += (float) $line->line_total_amount;
        }

        // For "all", only return days with activity to keep payload light.
        if ($start->diffInDays($end) > 120) {
            ksort($byDate);

            return array_values(array_map(static function (array $row) {
                $row['sales'] = round($row['sales'], 2);

                return $row;
            }, $byDate));
        }

        $cursor = $start->copy()->startOfDay();
        $out = [];
        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $row = $byDate[$key] ?? ['date' => $key, 'units' => 0, 'sales' => 0.0];
            $row['sales'] = round((float) $row['sales'], 2);
            $out[] = $row;
            $cursor->addDay();
        }

        return $out;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $lines
     * @param  list<array<string, mixed>>  $stockVariants
     * @return list<array{variant_id: int, sku: string|null, units_total: int, points: list<array{date: string, units: int}>}>
     */
    private function seriesByVariant(
        int $workspaceId,
        $lines,
        array $stockVariants,
        Carbon $start,
        Carbon $end,
    ): array {
        $skuById = [];
        $variantIds = [];
        foreach ($stockVariants as $row) {
            $vid = (int) ($row['id'] ?? 0);
            if ($vid <= 0) {
                continue;
            }
            $variantIds[] = $vid;
            $skuById[$vid] = isset($row['sku']) && is_string($row['sku']) ? $row['sku'] : null;
        }

        if ($variantIds === [] || $lines->isEmpty()) {
            return [];
        }

        $maps = VariantSalesAttribution::mapsForVariants($workspaceId, $variantIds);
        /** @var array<int, array<string, int>> $unitsByVariantDate */
        $unitsByVariantDate = [];
        /** @var array<int, int> $totals */
        $totals = [];

        foreach ($lines as $line) {
            $resolved = VariantSalesAttribution::resolveVariantId($line, $maps);
            if ($resolved === null) {
                continue;
            }

            $qty = (int) round((float) $line->quantity);
            if ($qty === 0) {
                continue;
            }

            $date = Carbon::parse($line->ordered_at)->toDateString();
            if (! isset($unitsByVariantDate[$resolved])) {
                $unitsByVariantDate[$resolved] = [];
                $totals[$resolved] = 0;
            }
            $unitsByVariantDate[$resolved][$date] = ($unitsByVariantDate[$resolved][$date] ?? 0) + $qty;
            $totals[$resolved] += $qty;
        }

        if ($totals === []) {
            return [];
        }

        arsort($totals);
        $fillAllDays = $start->diffInDays($end) <= 120;
        $out = [];

        foreach ($totals as $variantId => $unitsTotal) {
            $byDate = $unitsByVariantDate[$variantId] ?? [];
            $points = [];

            if ($fillAllDays) {
                $cursor = $start->copy()->startOfDay();
                while ($cursor->lte($end)) {
                    $key = $cursor->toDateString();
                    $points[] = [
                        'date' => $key,
                        'units' => (int) ($byDate[$key] ?? 0),
                    ];
                    $cursor->addDay();
                }
            } else {
                ksort($byDate);
                foreach ($byDate as $date => $units) {
                    $points[] = [
                        'date' => $date,
                        'units' => (int) $units,
                    ];
                }
            }

            $out[] = [
                'variant_id' => (int) $variantId,
                'sku' => $skuById[$variantId] ?? null,
                'units_total' => (int) $unitsTotal,
                'points' => $points,
            ];
        }

        return $out;
    }

    /**
     * Cross period units with current variant stock health.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $lines
     * @param  list<array<string, mixed>>  $stockVariants
     * @return array{
     *     stockout_units: int,
     *     at_risk_units: int,
     *     ok_units: int,
     *     unknown_units: int,
     *     stockout_pct: float,
     *     at_risk_pct: float,
     *     ok_pct: float
     * }
     */
    private function stockImpact($lines, array $stockVariants, int $workspaceId, int $periodUnits): array
    {
        $statusByVariant = [];
        $variantIds = [];
        foreach ($stockVariants as $row) {
            $vid = (int) ($row['id'] ?? 0);
            if ($vid <= 0) {
                continue;
            }
            $variantIds[] = $vid;
            $days = $row['forecast']['days_of_cover'] ?? null;
            if ($days !== null && (float) $days === 0.0) {
                $statusByVariant[$vid] = 'stockout';
            } elseif ($days === null) {
                $statusByVariant[$vid] = 'unknown';
            } elseif ((float) $days < StockDepletionForecastService::LOW_STOCK_COVER_DAYS) {
                $statusByVariant[$vid] = 'at_risk';
            } else {
                $statusByVariant[$vid] = 'ok';
            }
        }

        $stockout = 0;
        $atRisk = 0;
        $ok = 0;
        $unknown = 0;

        if ($variantIds !== [] && ! $lines->isEmpty()) {
            $maps = VariantSalesAttribution::mapsForVariants($workspaceId, $variantIds);
            foreach ($lines as $line) {
                $qty = (int) round((float) $line->quantity);
                if ($qty === 0) {
                    continue;
                }

                $resolved = VariantSalesAttribution::resolveVariantId($line, $maps);
                if ($resolved === null) {
                    $unknown += $qty;

                    continue;
                }

                $status = $statusByVariant[$resolved] ?? 'unknown';
                match ($status) {
                    'stockout' => $stockout += $qty,
                    'at_risk' => $atRisk += $qty,
                    'ok' => $ok += $qty,
                    default => $unknown += $qty,
                };
            }
        } elseif (! $lines->isEmpty()) {
            $unknown = $periodUnits;
        }

        $denom = $periodUnits > 0 ? $periodUnits : 0;
        $pct = static function (int $part) use ($denom): float {
            if ($denom <= 0) {
                return 0.0;
            }

            return round(($part / $denom) * 100, 1);
        };

        return [
            'stockout_units' => $stockout,
            'at_risk_units' => $atRisk,
            'ok_units' => $ok,
            'unknown_units' => $unknown,
            'stockout_pct' => $pct($stockout),
            'at_risk_pct' => $pct($atRisk),
            'ok_pct' => $pct($ok),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $lines
     * @return list<array<string, mixed>>
     */
    private function orderRows($lines, int $limit): array
    {
        $grouped = $lines->groupBy('order_id');
        $rows = [];

        foreach ($grouped as $orderId => $group) {
            $first = $group->first();
            $rows[] = [
                'order_id' => (int) $orderId,
                'external_order_id' => $first->external_order_id,
                'ordered_at' => $first->ordered_at
                    ? Carbon::parse($first->ordered_at)->toIso8601String()
                    : null,
                'status' => $first->status,
                'post_sale_outcome' => $first->post_sale_outcome,
                'buyer_external_id' => $first->buyer_external_id,
                'units' => (int) round((float) $group->sum(fn ($l) => (float) $l->quantity)),
                'line_total' => round((float) $group->sum(fn ($l) => (float) $l->line_total_amount), 2),
                'currency' => (string) ($first->currency_code ?: $first->order_currency ?: 'MXN'),
                'connection' => $first->connection_id ? [
                    'id' => (int) $first->connection_id,
                    'provider' => $first->connection_provider,
                    'display_name' => $first->connection_display_name,
                    'external_user_id' => $first->connection_external_user_id,
                    'color' => $first->connection_color,
                ] : null,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) ($b['ordered_at'] ?? ''), (string) ($a['ordered_at'] ?? ''));
        });

        return array_slice($rows, 0, $limit);
    }
}
