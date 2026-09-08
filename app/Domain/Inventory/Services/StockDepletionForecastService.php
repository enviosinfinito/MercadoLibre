<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Support\VariantSalesAttribution;
use App\Domain\Sales\Support\OrderSalesClassification;
use App\Models\ChannelListing;
use App\Models\OrderLine;
use App\Models\Variant;
use Illuminate\Support\Carbon;

final class StockDepletionForecastService
{
    public const DEFAULT_WINDOW_DAYS = 14;

    public const FALLBACK_WINDOW_DAYS = 90;

    public const LOW_STOCK_COVER_DAYS = 14;

    /**
     * Channel-first sellable qty: if the variant has a channel match, use channel stock
     * (even when 0); otherwise fall back to internal available.
     *
     * @return array{sellable: float, stock_basis: 'channel'|'internal'}
     */
    public function resolveSellable(?float $channelQty, float $internalAvailable): array
    {
        if ($channelQty !== null) {
            return [
                'sellable' => (float) $channelQty,
                'stock_basis' => 'channel',
            ];
        }

        return [
            'sellable' => $internalAvailable,
            'stock_basis' => 'internal',
        ];
    }

    /**
     * @param  list<int>  $variantIds
     * @param  array<int, float|null>  $channelByVariant  null = no channel match
     * @param  array<int, float|string>  $internalByVariant
     * @return array<int, array{
     *     units_sold_window: int,
     *     units_per_day: float,
     *     days_of_cover: float|null,
     *     stockout_date: string|null,
     *     window_days: int,
     *     velocity_window_days: int,
     *     low_stock: bool,
     *     stock_basis: 'channel'|'internal',
     *     sellable_qty: float
     * }>
     */
    public function forVariants(
        int $workspaceId,
        array $variantIds,
        array $channelByVariant = [],
        array $internalByVariant = [],
        int $windowDays = self::DEFAULT_WINDOW_DAYS,
    ): array {
        $variantIds = array_values(array_unique(array_filter(
            array_map('intval', $variantIds),
            fn (int $id) => $id > 0,
        )));
        $windowDays = max(1, $windowDays);

        $soldByVariant = $this->unitsSoldByVariant($workspaceId, $variantIds, $windowDays);
        $needsFallback = [];
        foreach ($variantIds as $variantId) {
            $hasChannelKey = array_key_exists($variantId, $channelByVariant);
            $channelQty = $hasChannelKey && $channelByVariant[$variantId] !== null
                ? (float) $channelByVariant[$variantId]
                : null;
            if (! $hasChannelKey) {
                $channelQty = null;
            }
            $internal = (float) ($internalByVariant[$variantId] ?? 0);
            $resolved = $this->resolveSellable($channelQty, $internal);
            $unitsSold = (int) ($soldByVariant[$variantId] ?? 0);
            if ($resolved['sellable'] > 0 && $unitsSold === 0) {
                $needsFallback[] = $variantId;
            }
        }

        $fallbackSold = [];
        if ($needsFallback !== [] && $windowDays < self::FALLBACK_WINDOW_DAYS) {
            $fallbackSold = $this->unitsSoldByVariant(
                $workspaceId,
                $needsFallback,
                self::FALLBACK_WINDOW_DAYS,
            );
        }

        $out = [];
        foreach ($variantIds as $variantId) {
            $hasChannelKey = array_key_exists($variantId, $channelByVariant);
            $channelQty = $hasChannelKey && $channelByVariant[$variantId] !== null
                ? (float) $channelByVariant[$variantId]
                : null;
            if (! $hasChannelKey) {
                $channelQty = null;
            }

            $internal = (float) ($internalByVariant[$variantId] ?? 0);
            $resolved = $this->resolveSellable($channelQty, $internal);
            $unitsSold = (int) ($soldByVariant[$variantId] ?? 0);
            $velocityWindow = $windowDays;

            if ($resolved['sellable'] > 0 && $unitsSold === 0 && isset($fallbackSold[$variantId]) && $fallbackSold[$variantId] > 0) {
                $unitsSold = (int) $fallbackSold[$variantId];
                $velocityWindow = self::FALLBACK_WINDOW_DAYS;
            }

            $out[$variantId] = $this->buildForecast(
                $resolved['sellable'],
                $unitsSold,
                $windowDays,
                $resolved['stock_basis'],
                $velocityWindow,
            );
        }

        return $out;
    }

    /**
     * Product-level bottleneck forecast + per-variant rows + assortment health.
     *
     * @param  list<array{
     *     id: int,
     *     sku: string|null,
     *     name: string|null,
     *     channel_stock: float|null,
     *     internal_available: float
     * }>  $variantRows
     * @return array{
     *     forecast: array<string, mixed>,
     *     aggregate_forecast: array<string, mixed>,
     *     assortment: array<string, mixed>,
     *     variants: list<array<string, mixed>>,
     *     stock_basis: 'channel'|'internal'
     * }
     */
    public function forProductWithVariants(
        int $workspaceId,
        int $productId,
        array $variantRows,
        int $windowDays = self::DEFAULT_WINDOW_DAYS,
    ): array {
        $windowDays = max(1, $windowDays);

        $variantIds = [];
        $channelByVariant = [];
        $internalByVariant = [];
        foreach ($variantRows as $row) {
            $id = (int) $row['id'];
            $variantIds[] = $id;
            $channelByVariant[$id] = array_key_exists('channel_stock', $row)
                ? $row['channel_stock']
                : null;
            $internalByVariant[$id] = (float) ($row['internal_available'] ?? 0);
        }

        $byVariant = $this->forVariants(
            $workspaceId,
            $variantIds,
            $channelByVariant,
            $internalByVariant,
            $windowDays,
        );

        $hasAnyChannel = false;
        $sellableTotal = 0.0;
        foreach ($variantRows as $row) {
            $id = (int) $row['id'];
            $channel = $channelByVariant[$id] ?? null;
            if ($channel !== null) {
                $hasAnyChannel = true;
            }
            $resolved = $this->resolveSellable(
                $channel !== null ? (float) $channel : null,
                (float) ($internalByVariant[$id] ?? 0),
            );
            $sellableTotal += $resolved['sellable'];
        }

        $productBasis = $hasAnyChannel ? 'channel' : 'internal';
        if ($hasAnyChannel) {
            $sellableTotal = 0.0;
            foreach ($variantRows as $row) {
                $channel = $channelByVariant[(int) $row['id']] ?? null;
                $sellableTotal += (float) ($channel ?? 0);
            }
        }

        $unitsSoldProduct = $this->unitsSoldForProduct($workspaceId, $productId, $variantIds, $windowDays);
        $aggregateForecast = $this->buildForecast($sellableTotal, $unitsSoldProduct, $windowDays, $productBasis);

        $variantsOut = [];
        foreach ($variantRows as $row) {
            $id = (int) $row['id'];
            $variantsOut[] = [
                'id' => $id,
                'sku' => $row['sku'] ?? null,
                'name' => $row['name'] ?? null,
                'channel_stock' => $channelByVariant[$id] ?? null,
                'internal_available' => round((float) ($internalByVariant[$id] ?? 0), 2),
                'forecast' => $byVariant[$id] ?? $this->buildForecast(0, 0, $windowDays, 'internal'),
            ];
        }

        // Assortment universe: channel-matched variants when any exist; else all variants.
        $assortmentRows = array_values(array_filter(
            $variantsOut,
            function (array $row) use ($hasAnyChannel) {
                if (! $hasAnyChannel) {
                    return true;
                }

                return $row['channel_stock'] !== null;
            },
        ));

        $assortment = $this->buildAssortmentStats($assortmentRows);
        $bottleneckForecast = $this->buildBottleneckForecast(
            $assortmentRows,
            $aggregateForecast,
            $productBasis,
            $windowDays,
            $assortment,
        );

        return [
            'forecast' => $bottleneckForecast,
            'aggregate_forecast' => $aggregateForecast,
            'assortment' => $assortment,
            'variants' => $variantsOut,
            'stock_basis' => $productBasis,
        ];
    }

    /**
     * @deprecated Prefer forProductWithVariants; kept for simple call sites.
     *
     * @return array<string, mixed>
     */
    public function forProduct(
        int $workspaceId,
        int $productId,
        float $available,
        int $windowDays = self::DEFAULT_WINDOW_DAYS,
        string $stockBasis = 'internal',
    ): array {
        $windowDays = max(1, $windowDays);

        $variantIds = Variant::query()
            ->where('workspace_id', $workspaceId)
            ->where('product_id', $productId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $unitsSold = $this->unitsSoldForProduct($workspaceId, $productId, $variantIds, $windowDays);

        return $this->buildForecast($available, $unitsSold, $windowDays, $stockBasis);
    }

    /**
     * Attribute sold units to variants without double-counting lines.
     *
     * Priority: variant_id → channel_listing_variant_id → external_item_id+external_variation_id.
     *
     * @param  list<int>  $variantIds
     * @return array<int, int>
     */
    private function unitsSoldByVariant(int $workspaceId, array $variantIds, int $windowDays): array
    {
        if ($variantIds === []) {
            return [];
        }

        $start = Carbon::now()->subDays($windowDays)->startOfDay();
        $maps = VariantSalesAttribution::mapsForVariants($workspaceId, $variantIds);
        $clvIds = array_keys($maps['clv_id_to_variant']);
        $externalPairToVariant = $maps['external_pair_to_variant'];

        $lines = OrderLine::query()
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('order_lines.workspace_id', $workspaceId)
            ->where('orders.ordered_at', '>=', $start)
            ->whereNotIn('orders.status', OrderSalesClassification::cancelledStatuses())
            ->where(function ($inner) {
                $inner->whereNull('orders.post_sale_outcome')
                    ->orWhereNotIn('orders.post_sale_outcome', OrderSalesClassification::reversedOutcomes());
            })
            ->where(function ($match) use ($variantIds, $clvIds, $externalPairToVariant) {
                $match->whereIn('order_lines.variant_id', $variantIds);
                if ($clvIds !== []) {
                    $match->orWhereIn('order_lines.channel_listing_variant_id', $clvIds);
                }
                if ($externalPairToVariant !== []) {
                    $match->orWhere(function ($pairs) use ($externalPairToVariant) {
                        foreach (array_keys($externalPairToVariant) as $i => $key) {
                            [$itemId, $varId] = explode('|', $key, 2);
                            $fn = $i === 0 ? 'where' : 'orWhere';
                            $pairs->{$fn}(function ($p) use ($itemId, $varId) {
                                $p->where('order_lines.external_item_id', $itemId)
                                    ->where('order_lines.external_variation_id', $varId);
                            });
                        }
                    });
                }
            })
            ->get([
                'order_lines.id',
                'order_lines.quantity',
                'order_lines.variant_id',
                'order_lines.channel_listing_variant_id',
                'order_lines.external_item_id',
                'order_lines.external_variation_id',
            ]);

        $out = array_fill_keys($variantIds, 0);
        foreach ($lines as $line) {
            $resolvedVariantId = VariantSalesAttribution::resolveVariantId($line, $maps);
            if ($resolvedVariantId === null || ! array_key_exists($resolvedVariantId, $out)) {
                continue;
            }

            $out[$resolvedVariantId] += (int) round((float) $line->quantity);
        }

        return $out;
    }

    /**
     * @param  list<int>  $variantIds
     */
    private function unitsSoldForProduct(
        int $workspaceId,
        int $productId,
        array $variantIds,
        int $windowDays,
    ): int {
        $start = Carbon::now()->subDays($windowDays)->startOfDay();
        $externalItemIds = $this->productExternalItemIds($workspaceId, $productId);

        $query = OrderLine::query()
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->leftJoin('variants', 'variants.id', '=', 'order_lines.variant_id')
            ->where('order_lines.workspace_id', $workspaceId)
            ->where('orders.ordered_at', '>=', $start)
            ->whereNotIn('orders.status', OrderSalesClassification::cancelledStatuses())
            ->where(function ($inner) {
                $inner->whereNull('orders.post_sale_outcome')
                    ->orWhereNotIn('orders.post_sale_outcome', OrderSalesClassification::reversedOutcomes());
            })
            ->where(function ($match) use ($productId, $variantIds, $externalItemIds) {
                if ($variantIds !== []) {
                    $match->whereIn('order_lines.variant_id', $variantIds)
                        ->orWhere('variants.product_id', $productId);
                } else {
                    $match->where('variants.product_id', $productId);
                }
                if ($externalItemIds !== []) {
                    $match->orWhereIn('order_lines.external_item_id', $externalItemIds);
                }
            });

        return (int) round((float) $query->sum('order_lines.quantity'));
    }

    /**
     * @return list<string>
     */
    private function productExternalItemIds(int $workspaceId, int $productId): array
    {
        $ids = [];

        $direct = ChannelListing::query()
            ->where('workspace_id', $workspaceId)
            ->where('product_id', $productId)
            ->whereNotNull('external_item_id')
            ->pluck('external_item_id');

        foreach ($direct as $ext) {
            if (is_string($ext) && $ext !== '') {
                $ids[] = $ext;
            }
        }

        $viaVariants = ChannelListing::query()
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('external_item_id')
            ->whereHas(
                'variants',
                fn ($q) => $q->whereHas(
                    'variant',
                    fn ($canonical) => $canonical->where('product_id', $productId),
                ),
            )
            ->pluck('external_item_id');

        foreach ($viaVariants as $ext) {
            if (is_string($ext) && $ext !== '') {
                $ids[] = $ext;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<array{id: int, sku: string|null, forecast: array<string, mixed>}>  $rows
     * @return array{
     *     variant_count: int,
     *     stockout_count: int,
     *     at_risk_count: int,
     *     ok_count: int,
     *     no_velocity_count: int,
     *     bottleneck_variant_id: int|null,
     *     bottleneck_sku: string|null
     * }
     */
    private function buildAssortmentStats(array $rows): array
    {
        $stockout = 0;
        $atRisk = 0;
        $ok = 0;
        $noVelocity = 0;
        $bottleneckId = null;
        $bottleneckSku = null;
        $bottleneckDays = null;

        foreach ($rows as $row) {
            $forecast = $row['forecast'] ?? [];
            $days = $forecast['days_of_cover'] ?? null;
            $sellable = (float) ($forecast['sellable_qty'] ?? 0);

            if ($days !== null && (float) $days === 0.0) {
                $stockout++;
                if ($bottleneckDays === null || 0.0 < $bottleneckDays) {
                    $bottleneckDays = 0.0;
                    $bottleneckId = (int) $row['id'];
                    $bottleneckSku = $row['sku'] ?? null;
                }

                continue;
            }

            if ($days === null) {
                if ($sellable > 0) {
                    $noVelocity++;
                }

                continue;
            }

            $daysF = (float) $days;
            if ($daysF < self::LOW_STOCK_COVER_DAYS) {
                $atRisk++;
            } else {
                $ok++;
            }

            if ($bottleneckDays === null || $daysF < $bottleneckDays) {
                $bottleneckDays = $daysF;
                $bottleneckId = (int) $row['id'];
                $bottleneckSku = $row['sku'] ?? null;
            }
        }

        return [
            'variant_count' => count($rows),
            'stockout_count' => $stockout,
            'at_risk_count' => $atRisk,
            'ok_count' => $ok,
            'no_velocity_count' => $noVelocity,
            'bottleneck_variant_id' => $bottleneckId,
            'bottleneck_sku' => $bottleneckSku,
        ];
    }

    /**
     * @param  list<array{id: int, sku: string|null, forecast: array<string, mixed>}>  $assortmentRows
     * @param  array<string, mixed>  $aggregateForecast
     * @param  array<string, mixed>  $assortment
     * @return array<string, mixed>
     */
    private function buildBottleneckForecast(
        array $assortmentRows,
        array $aggregateForecast,
        string $productBasis,
        int $windowDays,
        array $assortment,
    ): array {
        // Single variant (or empty): keep classic aggregate behavior.
        if (count($assortmentRows) <= 1) {
            $forecast = $aggregateForecast;
            $forecast['mode'] = 'single';

            return $forecast;
        }

        if (($assortment['stockout_count'] ?? 0) > 0) {
            $bottleneckRow = $this->findAssortmentRow($assortmentRows, $assortment['bottleneck_variant_id'] ?? null);
            $unitsSold = (int) ($bottleneckRow['forecast']['units_sold_window'] ?? 0);
            $velocityWindow = (int) ($bottleneckRow['forecast']['velocity_window_days'] ?? $windowDays);

            return array_merge(
                $this->buildForecast(0, $unitsSold, $windowDays, $productBasis, $velocityWindow),
                [
                    'mode' => 'bottleneck',
                    'sellable_qty' => 0.0,
                ],
            );
        }

        $minDays = null;
        $bottleneckForecast = null;
        foreach ($assortmentRows as $row) {
            $days = $row['forecast']['days_of_cover'] ?? null;
            if ($days === null) {
                continue;
            }
            $daysF = (float) $days;
            if ($minDays === null || $daysF < $minDays) {
                $minDays = $daysF;
                $bottleneckForecast = $row['forecast'];
            }
        }

        if ($bottleneckForecast === null) {
            // All stocked variants lack velocity — no inventing demand.
            return [
                'units_sold_window' => (int) ($aggregateForecast['units_sold_window'] ?? 0),
                'units_per_day' => 0.0,
                'days_of_cover' => null,
                'stockout_date' => null,
                'window_days' => $windowDays,
                'velocity_window_days' => $windowDays,
                'low_stock' => false,
                'stock_basis' => $productBasis,
                'sellable_qty' => (float) ($aggregateForecast['sellable_qty'] ?? 0),
                'mode' => 'bottleneck',
            ];
        }

        return array_merge($bottleneckForecast, [
            'mode' => 'bottleneck',
            'stock_basis' => $productBasis,
        ]);
    }

    /**
     * @param  list<array{id: int, forecast: array<string, mixed>}>  $rows
     * @return array{id: int, forecast: array<string, mixed>}|null
     */
    private function findAssortmentRow(array $rows, mixed $variantId): ?array
    {
        if ($variantId === null) {
            return $rows[0] ?? null;
        }
        $variantId = (int) $variantId;
        foreach ($rows as $row) {
            if ((int) $row['id'] === $variantId) {
                return $row;
            }
        }

        return $rows[0] ?? null;
    }

    /**
     * @return array{
     *     units_sold_window: int,
     *     units_per_day: float,
     *     days_of_cover: float|null,
     *     stockout_date: string|null,
     *     window_days: int,
     *     velocity_window_days: int,
     *     low_stock: bool,
     *     stock_basis: 'channel'|'internal',
     *     sellable_qty: float,
     *     mode?: string
     * }
     */
    private function buildForecast(
        float $available,
        int $unitsSold,
        int $windowDays,
        string $stockBasis = 'internal',
        ?int $velocityWindowDays = null,
    ): array {
        $velocityWindow = max(1, $velocityWindowDays ?? $windowDays);
        $unitsPerDay = round($unitsSold / $velocityWindow, 4);
        $basis = $stockBasis === 'channel' ? 'channel' : 'internal';

        if ($available <= 0) {
            return [
                'units_sold_window' => $unitsSold,
                'units_per_day' => $unitsPerDay,
                'days_of_cover' => 0.0,
                'stockout_date' => Carbon::now()->toDateString(),
                'window_days' => $windowDays,
                'velocity_window_days' => $velocityWindow,
                'low_stock' => true,
                'stock_basis' => $basis,
                'sellable_qty' => $available,
            ];
        }

        if ($unitsPerDay <= 0) {
            return [
                'units_sold_window' => $unitsSold,
                'units_per_day' => 0.0,
                'days_of_cover' => null,
                'stockout_date' => null,
                'window_days' => $windowDays,
                'velocity_window_days' => $velocityWindow,
                'low_stock' => false,
                'stock_basis' => $basis,
                'sellable_qty' => $available,
            ];
        }

        $daysOfCover = round($available / $unitsPerDay, 1);
        $stockoutDate = Carbon::now()->addDays((int) floor($daysOfCover))->toDateString();

        return [
            'units_sold_window' => $unitsSold,
            'units_per_day' => $unitsPerDay,
            'days_of_cover' => $daysOfCover,
            'stockout_date' => $stockoutDate,
            'window_days' => $windowDays,
            'velocity_window_days' => $velocityWindow,
            'low_stock' => $daysOfCover < self::LOW_STOCK_COVER_DAYS,
            'stock_basis' => $basis,
            'sellable_qty' => $available,
        ];
    }
}
