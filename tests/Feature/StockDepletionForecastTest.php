<?php

namespace Tests\Feature;

use App\Domain\Inventory\Actions\ReceiveInventory;
use App\Domain\Inventory\Services\StockDepletionForecastService;
use App\Domain\PostSale\Actions\ResolveOrderPostSaleOutcome;
use App\Models\ChannelListing;
use App\Models\ChannelListingVariant;
use App\Models\Connection;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Models\Warehouse;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StockDepletionForecastTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Workspace, 1: Variant, 2: Connection, 3: Product}
     */
    private function seedCatalogWithStock(float $receiveQty = 28): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['outbound_dry_run' => true]);

        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        Warehouse::factory()->default()->create([
            'workspace_id' => $workspace->id,
            'code' => 'DEFAULT',
        ]);

        $product = Product::factory()->create(['workspace_id' => $workspace->id]);
        $variant = Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'sku' => 'SKU-FORECAST-1',
        ]);

        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);

        if ($receiveQty > 0) {
            app(ReceiveInventory::class)->execute($workspace->id, [
                'variant_id' => $variant->id,
                'quantity' => $receiveQty,
                'unit_cost_amount' => 5,
                'unit_cost_currency' => 'MXN',
            ]);
        }

        return [$workspace, $variant, $connection, $product];
    }

    private function attachChannelStock(
        Workspace $workspace,
        Connection $connection,
        Product $product,
        Variant $variant,
        int $qty,
        string $externalItemId = 'MLM-FC-CHANNEL',
    ): ChannelListingVariant {
        $listing = ChannelListing::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'product_id' => $product->id,
            'provider' => 'mercadolibre',
            'external_item_id' => $externalItemId,
            'title' => 'Listing forecast',
            'status' => 'active',
        ]);

        return ChannelListingVariant::query()->create([
            'workspace_id' => $workspace->id,
            'channel_listing_id' => $listing->id,
            'variant_id' => $variant->id,
            'sku_external' => $variant->sku,
            'available_quantity' => $qty,
            'status' => 'active',
        ]);
    }

    private function createSoldLine(
        Workspace $workspace,
        Connection $connection,
        ?Variant $variant,
        float $qty,
        ?\DateTimeInterface $orderedAt = null,
        string $status = 'paid',
        ?string $postSaleOutcome = null,
        ?string $externalItemId = 'MLM-FC-1',
        ?int $channelListingVariantId = null,
        ?string $externalVariationId = null,
    ): void {
        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-FC-'.uniqid(),
            'status' => $status,
            'post_sale_outcome' => $postSaleOutcome,
            'currency_code' => 'MXN',
            'total_amount' => (string) ($qty * 10),
            'ordered_at' => $orderedAt ?? now()->subDays(3),
        ]);

        OrderLine::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'variant_id' => $variant?->id,
            'channel_listing_variant_id' => $channelListingVariantId,
            'external_item_id' => $externalItemId,
            'external_variation_id' => $externalVariationId,
            'sku' => $variant?->sku ?? 'UNMATCHED',
            'title' => 'Producto forecast',
            'quantity' => (string) $qty,
            'unit_price_amount' => '10',
            'currency_code' => 'MXN',
            'line_total_amount' => (string) ($qty * 10),
            'match_status' => $variant ? 'matched' : 'unmatched',
        ]);
    }

    #[Test]
    public function service_computes_days_of_cover_from_recent_sales(): void
    {
        [$workspace, $variant, $connection] = $this->seedCatalogWithStock(28);

        $this->createSoldLine($workspace, $connection, $variant, 14, now()->subDays(5));

        $forecast = app(StockDepletionForecastService::class)->forVariants(
            $workspace->id,
            [$variant->id],
            [], // no channel → internal
            [$variant->id => 28],
            14,
        )[$variant->id];

        $this->assertSame(14, $forecast['units_sold_window']);
        $this->assertEquals(1.0, $forecast['units_per_day']);
        $this->assertEquals(28.0, $forecast['days_of_cover']);
        $this->assertFalse($forecast['low_stock']);
        $this->assertSame('internal', $forecast['stock_basis']);
        $this->assertNotNull($forecast['stockout_date']);
    }

    #[Test]
    public function service_prefers_channel_stock_over_internal(): void
    {
        [$workspace, $variant, $connection] = $this->seedCatalogWithStock(0);

        // 14 u in 14d => 1 u/day; channel 28 => 28 days cover (not Agotado)
        $this->createSoldLine($workspace, $connection, $variant, 14, now()->subDays(3));

        $forecast = app(StockDepletionForecastService::class)->forVariants(
            $workspace->id,
            [$variant->id],
            [$variant->id => 28],
            [$variant->id => 0],
            14,
        )[$variant->id];

        $this->assertSame('channel', $forecast['stock_basis']);
        $this->assertEquals(28.0, $forecast['days_of_cover']);
        $this->assertFalse($forecast['low_stock']);
        $this->assertSame(28.0, $forecast['sellable_qty']);
    }

    #[Test]
    public function service_marks_low_stock_when_cover_under_14_days(): void
    {
        [$workspace, $variant, $connection] = $this->seedCatalogWithStock(10);

        $this->createSoldLine($workspace, $connection, $variant, 20, now()->subDays(2));

        $forecast = app(StockDepletionForecastService::class)->forVariants(
            $workspace->id,
            [$variant->id],
            [],
            [$variant->id => 10],
            14,
        )[$variant->id];

        $this->assertTrue($forecast['low_stock']);
        $this->assertNotNull($forecast['days_of_cover']);
        $this->assertLessThan(14, $forecast['days_of_cover']);
    }

    #[Test]
    public function service_returns_null_cover_without_recent_sales(): void
    {
        [$workspace, $variant] = $this->seedCatalogWithStock(10);

        $forecast = app(StockDepletionForecastService::class)->forVariants(
            $workspace->id,
            [$variant->id],
            [],
            [$variant->id => 10],
            14,
        )[$variant->id];

        $this->assertSame(0, $forecast['units_sold_window']);
        $this->assertSame(0.0, $forecast['units_per_day']);
        $this->assertNull($forecast['days_of_cover']);
        $this->assertNull($forecast['stockout_date']);
        $this->assertFalse($forecast['low_stock']);
    }

    #[Test]
    public function service_treats_zero_sellable_as_depleted(): void
    {
        [$workspace, $variant, $connection] = $this->seedCatalogWithStock(0);

        $this->createSoldLine($workspace, $connection, $variant, 5, now()->subDays(1));

        $forecast = app(StockDepletionForecastService::class)->forVariants(
            $workspace->id,
            [$variant->id],
            [$variant->id => 0],
            [$variant->id => 0],
            14,
        )[$variant->id];

        $this->assertSame(0.0, $forecast['days_of_cover']);
        $this->assertTrue($forecast['low_stock']);
        $this->assertSame('channel', $forecast['stock_basis']);
        $this->assertSame(now()->toDateString(), $forecast['stockout_date']);
    }

    #[Test]
    public function service_excludes_cancelled_and_reversed_orders(): void
    {
        [$workspace, $variant, $connection] = $this->seedCatalogWithStock(20);

        $this->createSoldLine($workspace, $connection, $variant, 10, now()->subDays(2), 'cancelled');
        $this->createSoldLine(
            $workspace,
            $connection,
            $variant,
            8,
            now()->subDays(2),
            'paid',
            ResolveOrderPostSaleOutcome::RETURNED,
        );
        $this->createSoldLine($workspace, $connection, $variant, 4, now()->subDays(2));

        $forecast = app(StockDepletionForecastService::class)->forVariants(
            $workspace->id,
            [$variant->id],
            [],
            [$variant->id => 20],
            14,
        )[$variant->id];

        $this->assertSame(4, $forecast['units_sold_window']);
    }

    #[Test]
    public function product_forecast_counts_sales_by_external_item_without_variant(): void
    {
        [$workspace, $variant, $connection, $product] = $this->seedCatalogWithStock(0);
        $this->attachChannelStock($workspace, $connection, $product, $variant, 100, 'MLM-EXT-ONLY');

        // Sale without variant_id but with listing external id
        $this->createSoldLine(
            $workspace,
            $connection,
            null,
            14,
            now()->subDays(2),
            'paid',
            null,
            'MLM-EXT-ONLY',
        );

        $bundle = app(StockDepletionForecastService::class)->forProductWithVariants(
            $workspace->id,
            $product->id,
            [[
                'id' => $variant->id,
                'sku' => $variant->sku,
                'name' => $variant->name,
                'channel_stock' => 100.0,
                'internal_available' => 0.0,
            ]],
            14,
        );

        $this->assertSame(14, $bundle['forecast']['units_sold_window']);
        $this->assertSame('channel', $bundle['stock_basis']);
        $this->assertEquals(100.0, $bundle['forecast']['sellable_qty']);
        $this->assertEquals(100.0, $bundle['forecast']['days_of_cover']);
        $this->assertFalse($bundle['forecast']['low_stock']);
        $this->assertSame('single', $bundle['forecast']['mode'] ?? 'single');
    }

    #[Test]
    public function multi_variant_headline_is_bottleneck_not_aggregate_sum(): void
    {
        [$workspace, $variant, $connection, $product] = $this->seedCatalogWithStock(0);
        $variantB = Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'sku' => 'SKU-TALLA-B',
        ]);

        $this->createSoldLine($workspace, $connection, $variant, 14, now()->subDays(2));
        $this->createSoldLine($workspace, $connection, $variantB, 14, now()->subDays(2));

        $bundle = app(StockDepletionForecastService::class)->forProductWithVariants(
            $workspace->id,
            $product->id,
            [
                [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'name' => null,
                    'channel_stock' => 40.0,
                    'internal_available' => 0.0,
                ],
                [
                    'id' => $variantB->id,
                    'sku' => $variantB->sku,
                    'name' => null,
                    'channel_stock' => 10.0,
                    'internal_available' => 0.0,
                ],
            ],
            14,
        );

        // Aggregate would be 50/2 = 25 days; bottleneck is 10 days (talla B).
        $this->assertSame('bottleneck', $bundle['forecast']['mode']);
        $this->assertEquals(10.0, $bundle['forecast']['days_of_cover']);
        $this->assertEquals(25.0, $bundle['aggregate_forecast']['days_of_cover']);
        $this->assertSame(0, $bundle['assortment']['stockout_count']);
        $this->assertSame(1, $bundle['assortment']['at_risk_count']);
        $this->assertSame($variantB->id, $bundle['assortment']['bottleneck_variant_id']);
    }

    #[Test]
    public function multi_variant_with_stockout_headline_is_agotado(): void
    {
        [$workspace, $variant, $connection, $product] = $this->seedCatalogWithStock(0);
        $variantB = Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'sku' => 'SKU-OK',
        ]);

        $this->createSoldLine($workspace, $connection, $variantB, 14, now()->subDays(2));

        $bundle = app(StockDepletionForecastService::class)->forProductWithVariants(
            $workspace->id,
            $product->id,
            [
                [
                    'id' => $variant->id,
                    'sku' => 'SKU-AGOTADA',
                    'name' => null,
                    'channel_stock' => 0.0,
                    'internal_available' => 0.0,
                ],
                [
                    'id' => $variantB->id,
                    'sku' => $variantB->sku,
                    'name' => null,
                    'channel_stock' => 100.0,
                    'internal_available' => 0.0,
                ],
            ],
            14,
        );

        $this->assertSame('bottleneck', $bundle['forecast']['mode']);
        $this->assertSame(0.0, $bundle['forecast']['days_of_cover']);
        $this->assertTrue($bundle['forecast']['low_stock']);
        $this->assertSame(1, $bundle['assortment']['stockout_count']);
        $this->assertSame($variant->id, $bundle['assortment']['bottleneck_variant_id']);
        // Aggregate still looks healthy — must not be the headline.
        $this->assertGreaterThan(0, $bundle['aggregate_forecast']['days_of_cover']);
    }

    #[Test]
    public function variant_sales_count_via_channel_listing_variant_id(): void
    {
        [$workspace, $variant, $connection, $product] = $this->seedCatalogWithStock(0);
        $clv = $this->attachChannelStock($workspace, $connection, $product, $variant, 28, 'MLM-CLV');

        $this->createSoldLine(
            $workspace,
            $connection,
            null,
            14,
            now()->subDays(2),
            'paid',
            null,
            'MLM-CLV',
            $clv->id,
        );

        $forecast = app(StockDepletionForecastService::class)->forVariants(
            $workspace->id,
            [$variant->id],
            [$variant->id => 28],
            [$variant->id => 0],
            14,
        )[$variant->id];

        $this->assertSame(14, $forecast['units_sold_window']);
        $this->assertEquals(28.0, $forecast['days_of_cover']);
    }

    #[Test]
    public function variant_sales_count_via_external_variation_id(): void
    {
        [$workspace, $variant, $connection, $product] = $this->seedCatalogWithStock(0);
        $listing = ChannelListing::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'product_id' => $product->id,
            'provider' => 'mercadolibre',
            'external_item_id' => 'MLM-VAR-PAIR',
            'title' => 'Listing pair',
            'status' => 'active',
        ]);
        ChannelListingVariant::query()->create([
            'workspace_id' => $workspace->id,
            'channel_listing_id' => $listing->id,
            'variant_id' => $variant->id,
            'external_variation_id' => '999001',
            'sku_external' => $variant->sku,
            'available_quantity' => 28,
            'status' => 'active',
        ]);

        $this->createSoldLine(
            $workspace,
            $connection,
            null,
            14,
            now()->subDays(2),
            'paid',
            null,
            'MLM-VAR-PAIR',
            null,
            '999001',
        );

        $forecast = app(StockDepletionForecastService::class)->forVariants(
            $workspace->id,
            [$variant->id],
            [$variant->id => 28],
            [$variant->id => 0],
            14,
        )[$variant->id];

        $this->assertSame(14, $forecast['units_sold_window']);
        $this->assertEquals(28.0, $forecast['days_of_cover']);
    }

    #[Test]
    public function products_sales_uses_channel_and_exposes_variant_rows(): void
    {
        [$workspace, $variant, $connection, $product] = $this->seedCatalogWithStock(0);
        $variantB = Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'sku' => 'SKU-FORECAST-2',
        ]);

        $this->attachChannelStock($workspace, $connection, $product, $variant, 40, 'MLM-A');
        $listingB = ChannelListing::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'product_id' => $product->id,
            'provider' => 'mercadolibre',
            'external_item_id' => 'MLM-B',
            'title' => 'Listing B',
            'status' => 'active',
        ]);
        ChannelListingVariant::query()->create([
            'workspace_id' => $workspace->id,
            'channel_listing_id' => $listingB->id,
            'variant_id' => $variantB->id,
            'sku_external' => $variantB->sku,
            'available_quantity' => 10,
            'status' => 'active',
        ]);

        $this->createSoldLine($workspace, $connection, $variant, 14, now()->subDays(2), 'paid', null, 'MLM-A');
        $this->createSoldLine($workspace, $connection, $variantB, 14, now()->subDays(2), 'paid', null, 'MLM-B');

        $this->get(route('products.sales', $product))
            ->assertOk()
            ->assertJsonPath('stock.channel_stock', 50)
            ->assertJsonPath('stock.internal_available', 0)
            ->assertJsonPath('stock.stock_basis', 'channel')
            ->assertJsonPath('stock.forecast.mode', 'bottleneck')
            ->assertJsonPath('stock.forecast.days_of_cover', 10)
            ->assertJsonPath('stock.assortment.stockout_count', 0)
            ->assertJsonPath('stock.assortment.at_risk_count', 1)
            ->assertJsonPath('stock.low_stock', true)
            ->assertJsonCount(2, 'stock.variants')
            ->assertJsonStructure([
                'stock' => [
                    'assortment' => [
                        'variant_count',
                        'stockout_count',
                        'at_risk_count',
                        'ok_count',
                        'no_velocity_count',
                        'bottleneck_variant_id',
                        'bottleneck_sku',
                    ],
                    'aggregate_forecast' => [
                        'days_of_cover',
                    ],
                    'variants' => [
                        [
                            'id',
                            'sku',
                            'channel_stock',
                            'internal_available',
                            'forecast' => [
                                'units_sold_window',
                                'days_of_cover',
                                'stock_basis',
                            ],
                        ],
                    ],
                ],
                'series_by_variant',
                'stock_impact' => [
                    'stockout_units',
                    'at_risk_units',
                    'ok_units',
                    'unknown_units',
                    'stockout_pct',
                    'at_risk_pct',
                    'ok_pct',
                ],
            ]);
    }

    #[Test]
    public function products_sales_series_by_variant_has_daily_points_for_each_selling_size(): void
    {
        [$workspace, $variant, $connection, $product] = $this->seedCatalogWithStock(0);
        $variantB = Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'sku' => 'SKU-SERIES-B',
        ]);

        $this->attachChannelStock($workspace, $connection, $product, $variant, 20, 'MLM-SER-A');
        $this->attachChannelStock($workspace, $connection, $product, $variantB, 20, 'MLM-SER-B');

        $dayA = now()->subDays(3)->startOfDay();
        $dayB = now()->subDays(1)->startOfDay();

        $this->createSoldLine($workspace, $connection, $variant, 5, $dayA, 'paid', null, 'MLM-SER-A');
        $this->createSoldLine($workspace, $connection, $variant, 3, $dayB, 'paid', null, 'MLM-SER-A');
        $this->createSoldLine($workspace, $connection, $variantB, 2, $dayA, 'paid', null, 'MLM-SER-B');

        $response = $this->get(route('products.sales', [
            'product' => $product,
            'period' => 'last_7_days',
        ]))->assertOk();

        $series = $response->json('series_by_variant');
        $this->assertCount(2, $series);
        $this->assertSame((int) $variant->id, (int) $series[0]['variant_id']);
        $this->assertSame(8, (int) $series[0]['units_total']);
        $this->assertSame((int) $variantB->id, (int) $series[1]['variant_id']);
        $this->assertSame(2, (int) $series[1]['units_total']);

        $pointsA = collect($series[0]['points'])->keyBy('date');
        $this->assertSame(5, (int) $pointsA[$dayA->toDateString()]['units']);
        $this->assertSame(3, (int) $pointsA[$dayB->toDateString()]['units']);

        $pointsB = collect($series[1]['points'])->keyBy('date');
        $this->assertSame(2, (int) $pointsB[$dayA->toDateString()]['units']);
    }

    #[Test]
    public function products_sales_stock_impact_marks_sold_units_from_stockout_variant(): void
    {
        [$workspace, $variant, $connection, $product] = $this->seedCatalogWithStock(0);
        $variantOk = Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'sku' => 'SKU-OK',
        ]);

        // Sold-out size today; healthy size with cover.
        $this->attachChannelStock($workspace, $connection, $product, $variant, 0, 'MLM-OUT');
        $this->attachChannelStock($workspace, $connection, $product, $variantOk, 100, 'MLM-OK');

        $this->createSoldLine($workspace, $connection, $variant, 6, now()->subDays(2), 'paid', null, 'MLM-OUT');
        $this->createSoldLine($workspace, $connection, $variantOk, 4, now()->subDays(2), 'paid', null, 'MLM-OK');

        $this->get(route('products.sales', [
            'product' => $product,
            'period' => 'last_7_days',
        ]))
            ->assertOk()
            ->assertJsonPath('summary.units_sold', 10)
            ->assertJsonPath('stock_impact.stockout_units', 6)
            ->assertJsonPath('stock_impact.ok_units', 4)
            ->assertJsonPath('stock_impact.stockout_pct', 60)
            ->assertJsonPath('stock_impact.ok_pct', 40);
    }

    #[Test]
    public function products_sales_single_variant_still_returns_series_payload(): void
    {
        [$workspace, $variant, $connection, $product] = $this->seedCatalogWithStock(20);
        $this->createSoldLine($workspace, $connection, $variant, 4, now()->subDays(1));

        $this->get(route('products.sales', [
            'product' => $product,
            'period' => 'last_7_days',
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'stock.variants')
            ->assertJsonCount(1, 'series_by_variant')
            ->assertJsonPath('series_by_variant.0.units_total', 4)
            ->assertJsonStructure([
                'stock_impact' => [
                    'stockout_pct',
                    'at_risk_pct',
                    'ok_pct',
                ],
            ]);
    }

    #[Test]
    public function stock_show_includes_forecast_with_stock_basis(): void
    {
        [$workspace, $variant, $connection, $product] = $this->seedCatalogWithStock(14);
        $this->attachChannelStock($workspace, $connection, $product, $variant, 100);
        $this->createSoldLine($workspace, $connection, $variant, 14, now()->subDays(3));

        $this->get(route('stock.show', $variant))
            ->assertOk()
            ->assertJsonPath('variant.forecast.window_days', 14)
            ->assertJsonPath('variant.forecast.units_sold_window', 14)
            ->assertJsonPath('variant.forecast.stock_basis', 'channel')
            ->assertJsonPath('variant.forecast.sellable_qty', 100)
            ->assertJsonStructure([
                'variant' => [
                    'forecast' => [
                        'units_sold_window',
                        'units_per_day',
                        'days_of_cover',
                        'stockout_date',
                        'window_days',
                        'low_stock',
                        'stock_basis',
                        'sellable_qty',
                    ],
                ],
            ]);
    }

    #[Test]
    public function stock_index_exposes_forecast_and_filters_by_low_cover(): void
    {
        [$workspace, $variant, $connection] = $this->seedCatalogWithStock(5);
        $this->createSoldLine($workspace, $connection, $variant, 20, now()->subDays(1));

        $this->get(route('stock.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Stock/Index')
                ->has('rows.data.0.forecast')
                ->where('rows.data.0.flags.low_stock', true)
                ->where('rows.data.0.forecast.stock_basis', 'internal'));

        $this->get(route('stock.index', ['low_stock' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Stock/Index')
                ->has('rows.data', 1)
                ->where('rows.data.0.id', $variant->id));
    }
}
