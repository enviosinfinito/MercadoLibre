<?php

namespace Tests\Feature;

use App\Domain\Sales\Actions\UpsertCanonicalOrder;
use App\Jobs\ProcessCanonicalOrderJob;
use App\Models\ChannelListing;
use App\Models\ChannelListingVariant;
use App\Models\Connection;
use App\Models\EncryptedCredential;
use App\Models\FullStockOperation;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\RawResourceSnapshot;
use App\Models\Reservation;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Variant;
use App\Models\Warehouse;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderStockMappingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Connection}
     */
    private function actingMemberWithConnection(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['outbound_dry_run' => true]);

        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        $connection = Connection::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '112184176',
            'status' => 'active',
            'token_generation' => 1,
        ]);

        $credential = new EncryptedCredential(['connection_id' => $connection->id]);
        $credential->setPlainPayload([
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh',
            'user_id' => '112184176',
        ]);
        $credential->save();

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$user, $workspace, $connection];
    }

    /**
     * @return array{0: Variant, 1: ChannelListingVariant, 2: ChannelListing}
     */
    private function seedListing(
        Workspace $workspace,
        Connection $connection,
        string $sku = 'FULL-001',
        string $itemId = 'MLM123',
        ?string $logisticType = 'fulfillment',
    ): array {
        $product = Product::factory()->create(['workspace_id' => $workspace->id]);
        $variant = Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'sku' => $sku,
            'status' => 'active',
        ]);

        $listing = ChannelListing::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'external_item_id' => $itemId,
            'title' => 'Full listing',
            'status' => 'active',
            'logistic_type' => $logisticType,
            'inventory_id' => 'DEHW09303',
        ]);

        $clv = ChannelListingVariant::query()->create([
            'workspace_id' => $workspace->id,
            'channel_listing_id' => $listing->id,
            'variant_id' => $variant->id,
            'inventory_id' => 'DEHW09303',
            'sku_external' => $sku,
            'status' => 'active',
            'available_quantity' => 10,
        ]);

        return [$variant, $clv, $listing];
    }

    #[Test]
    public function upsert_matches_order_line_by_external_item_id_when_sku_differs(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithConnection();
        [$variant, $clv] = $this->seedListing($workspace, $connection);

        $order = app(UpsertCanonicalOrder::class)->execute($workspace->id, $connection->id, [
            'external_order_id' => '2000017840106932',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '214.50',
            'ordered_at' => now()->subDays(2),
            'paid_at' => now()->subDays(2),
            'lines' => [[
                'external_item_id' => 'MLM123',
                'sku' => 'SELLER-OTHER',
                'title' => 'Producto Full',
                'quantity' => 1,
                'unit_price_amount' => '214.50',
                'currency_code' => 'MXN',
                'line_total_amount' => '214.50',
            ]],
        ]);

        $line = $order->lines->first();
        $this->assertNotNull($line);
        $this->assertSame($variant->id, $line->variant_id);
        $this->assertSame($clv->id, $line->channel_listing_variant_id);
        $this->assertSame('matched', $line->match_status);
    }

    #[Test]
    public function timeline_reserved_is_pending_without_stock_evidence(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithConnection();

        $order = app(UpsertCanonicalOrder::class)->execute($workspace->id, $connection->id, [
            'external_order_id' => '2000010001',
            'status' => 'delivered',
            'currency_code' => 'MXN',
            'total_amount' => '100',
            'ordered_at' => now()->subDays(2),
            'paid_at' => now()->subDays(2),
            'lines' => [[
                'external_item_id' => 'MLM-UNMATCHED',
                'sku' => 'NO-SKU',
                'title' => 'Sin match',
                'quantity' => 1,
                'unit_price_amount' => '100',
                'currency_code' => 'MXN',
                'line_total_amount' => '100',
            ]],
        ]);

        $response = $this->getJson(route('orders.show', $order));
        $response->assertOk();

        $timeline = collect($response->json('timeline'))->keyBy('key');
        $this->assertFalse($timeline['reserved']['done']);
        $this->assertNull($timeline['reserved']['at']);
    }

    #[Test]
    public function timeline_reserved_uses_internal_reservation(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithConnection();
        [$variant] = $this->seedListing($workspace, $connection, logisticType: 'drop_off');

        $warehouse = Warehouse::factory()->default()->create([
            'workspace_id' => $workspace->id,
            'is_active' => true,
        ]);

        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => '2000010002',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '100',
            'ordered_at' => now()->subDays(2),
            'paid_at' => now()->subDays(2),
        ]);

        $item = InventoryItem::query()->create([
            'workspace_id' => $workspace->id,
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
        ]);

        $line = OrderLine::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'variant_id' => $variant->id,
            'sku' => $variant->sku,
            'title' => 'iPhone',
            'quantity' => '1',
            'unit_price_amount' => '100',
            'currency_code' => 'MXN',
            'line_total_amount' => '100',
            'match_status' => 'matched',
        ]);

        $reservedAt = now()->subDays(2)->startOfSecond();
        Reservation::query()->create([
            'workspace_id' => $workspace->id,
            'inventory_item_id' => $item->id,
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'order_id' => $order->id,
            'order_line_id' => $line->id,
            'quantity' => '1',
            'status' => 'active',
            'idempotency_key' => 'reserve:order_line:'.$line->id,
            'reserved_at' => $reservedAt,
        ]);

        $response = $this->getJson(route('orders.show', $order));
        $response->assertOk();

        $timeline = collect($response->json('timeline'))->keyBy('key');
        $this->assertTrue($timeline['reserved']['done']);
        $this->assertNotNull($timeline['reserved']['at']);
        $this->assertTrue(Carbon::parse($timeline['reserved']['at'])->equalTo($reservedAt));
    }

    #[Test]
    public function timeline_and_slide_use_full_sale_confirmation(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithConnection();
        [$variant] = $this->seedListing($workspace, $connection);

        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => '2000017840106932',
            'status' => 'delivered',
            'currency_code' => 'MXN',
            'total_amount' => '214.50',
            'ordered_at' => now()->subDays(2),
            'paid_at' => now()->subDays(2),
            'meta' => ['shipping' => ['id' => '400001']],
        ]);

        OrderLine::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'external_item_id' => 'MLM123',
            'sku' => 'SELLER-OTHER',
            'title' => 'Producto Full',
            'quantity' => '1',
            'unit_price_amount' => '214.50',
            'currency_code' => 'MXN',
            'line_total_amount' => '214.50',
            'match_status' => 'unmatched',
        ]);

        Shipment::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'external_shipment_id' => '400001',
            'status' => 'delivered',
            'shipped_at' => now()->subDays(2),
            'delivered_at' => now()->subDays(2),
        ]);

        $occurredAt = now()->subDays(2)->startOfSecond();
        FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_operation_id' => '306718974',
            'operation_type' => 'SALE_CONFIRMATION',
            'inventory_id' => 'DEHW09303',
            'variant_id' => $variant->id,
            'available_quantity_delta' => '-1',
            'occurred_at' => $occurredAt,
            'external_references' => [
                ['type' => 'shipment_id', 'value' => '400001'],
            ],
        ]);

        FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_operation_id' => '306718975',
            'operation_type' => 'WITHDRAWAL_RESERVATION',
            'inventory_id' => 'DEHW09303',
            'occurred_at' => now()->subDays(3),
            'external_references' => [
                ['type' => 'shipment_id', 'value' => '999999'],
            ],
        ]);

        $show = $this->getJson(route('orders.show', $order));
        $show->assertOk();

        $timeline = collect($show->json('timeline'))->keyBy('key');
        $this->assertTrue($timeline['reserved']['done']);
        $this->assertNotNull($timeline['reserved']['at']);
        $this->assertTrue(Carbon::parse($timeline['reserved']['at'])->equalTo($occurredAt));

        $slide = $this->getJson(route('orders.reservations', $order));
        $slide->assertOk();
        $slide->assertJsonCount(0, 'reservations');
        $slide->assertJsonCount(1, 'full_operations');
        $slide->assertJsonPath('full_operations.0.operation_type', 'SALE_CONFIRMATION');
        $slide->assertJsonPath('full_operations.0.variant_sku', 'FULL-001');
    }

    #[Test]
    public function process_canonical_order_skips_local_reserve_for_full_fulfillment(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithConnection();
        [$variant, $clv] = $this->seedListing($workspace, $connection);

        Warehouse::factory()->default()->create([
            'workspace_id' => $workspace->id,
            'is_active' => true,
        ]);

        $snapshot = RawResourceSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'resource_type' => 'order',
            'external_id' => '2000017840106932',
            'payload' => [
                'id' => '2000017840106932',
                'status' => 'delivered',
                'currency_id' => 'MXN',
                'total_amount' => 214.5,
                'date_created' => now()->subDays(2)->toIso8601String(),
                'date_closed' => now()->subDays(2)->toIso8601String(),
                'order_items' => [[
                    'quantity' => 1,
                    'unit_price' => 214.5,
                    'item' => [
                        'id' => 'MLM123',
                        'title' => 'Producto Full',
                        'seller_sku' => 'SELLER-OTHER',
                    ],
                ]],
            ],
            'checksum' => 'full-order',
            'fetched_at' => now(),
        ]);

        (new ProcessCanonicalOrderJob(
            $workspace->id,
            $connection->id,
            (int) $snapshot->id,
        ))->handle();

        $order = Order::query()->where('external_order_id', '2000017840106932')->firstOrFail();
        $line = $order->lines()->first();

        $this->assertNotNull($line);
        $this->assertSame($variant->id, $line->variant_id);
        $this->assertSame($clv->id, $line->channel_listing_variant_id);
        $this->assertSame('matched', $line->match_status);
        $this->assertSame(0, Reservation::query()->where('order_id', $order->id)->count());
    }

    #[Test]
    public function process_canonical_order_skips_local_reserve_when_sale_confirmation_exists(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithConnection();
        $this->seedListing($workspace, $connection, logisticType: 'drop_off');

        Warehouse::factory()->default()->create([
            'workspace_id' => $workspace->id,
            'is_active' => true,
        ]);

        FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_operation_id' => 'sale-1',
            'operation_type' => 'SALE_CONFIRMATION',
            'inventory_id' => 'DEHW09303',
            'occurred_at' => now()->subDay(),
            'external_references' => [
                ['type' => 'order_id', 'value' => '2000010003'],
            ],
        ]);

        $snapshot = RawResourceSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'resource_type' => 'order',
            'external_id' => '2000010003',
            'payload' => [
                'id' => '2000010003',
                'status' => 'paid',
                'currency_id' => 'MXN',
                'total_amount' => 100,
                'date_created' => now()->subDay()->toIso8601String(),
                'date_closed' => now()->subDay()->toIso8601String(),
                'order_items' => [[
                    'quantity' => 1,
                    'unit_price' => 100,
                    'item' => [
                        'id' => 'MLM123',
                        'title' => 'Producto',
                        'seller_sku' => 'FULL-001',
                    ],
                ]],
            ],
            'checksum' => 'sale-conf',
            'fetched_at' => now(),
        ]);

        (new ProcessCanonicalOrderJob(
            $workspace->id,
            $connection->id,
            (int) $snapshot->id,
        ))->handle();

        $order = Order::query()->where('external_order_id', '2000010003')->firstOrFail();
        $this->assertSame(0, Reservation::query()->where('order_id', $order->id)->count());
    }
}
