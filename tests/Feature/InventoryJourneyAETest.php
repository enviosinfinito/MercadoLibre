<?php

namespace Tests\Feature;

use App\Domain\Inventory\Actions\BuildVariantInventoryTimeline;
use App\Domain\Inventory\Actions\MatchMarketplaceInbound;
use App\Domain\Inventory\Actions\ReceiveInventory;
use App\Domain\Inventory\Actions\ReturnStockFromFull;
use App\Domain\Inventory\Actions\ShipStockToFull;
use App\Domain\Inventory\Actions\SyncMercadoLibreFullStockOperations;
use App\Models\ChannelListing;
use App\Models\ChannelListingVariant;
use App\Models\Connection;
use App\Models\EncryptedCredential;
use App\Models\FullStockOperation;
use App\Models\InventoryBalance;
use App\Models\MarketplaceInbound;
use App\Models\Product;
use App\Models\SupplierPurchaseOrder;
use App\Models\SupplierPurchaseOrderLine;
use App\Models\User;
use App\Models\Variant;
use App\Models\Warehouse;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InventoryJourneyAETest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Workspace, 1: Variant, 2: Warehouse, 3: Connection}
     */
    private function seedWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['outbound_dry_run' => true]);
        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);
        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        $warehouse = Warehouse::factory()->default()->create([
            'workspace_id' => $workspace->id,
            'code' => 'DEFAULT',
        ]);
        $product = Product::factory()->create(['workspace_id' => $workspace->id]);
        $variant = Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'sku' => 'SKU-AE-1',
        ]);
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);

        return [$workspace, $variant, $warehouse, $connection];
    }

    private function onHand(int $workspaceId, int $variantId): string
    {
        $sum = '0';
        $balances = InventoryBalance::query()
            ->where('workspace_id', $workspaceId)
            ->whereHas('inventoryItem', fn ($q) => $q->where('variant_id', $variantId))
            ->get();
        foreach ($balances as $balance) {
            $sum = bcadd($sum, (string) $balance->quantity_on_hand, 6);
        }

        return $sum;
    }

    #[Test]
    public function receive_against_po_uses_actual_qty_and_records_variance(): void
    {
        Queue::fake();
        [$workspace, $variant, $warehouse] = $this->seedWorkspace();

        $po = SupplierPurchaseOrder::factory()->create([
            'workspace_id' => $workspace->id,
            'supplier_name' => 'Proveedor X',
            'status' => SupplierPurchaseOrder::STATUS_ORDERED,
            'ordered_at' => now()->subDay(),
        ]);
        $line = SupplierPurchaseOrderLine::factory()->create([
            'workspace_id' => $workspace->id,
            'supplier_purchase_order_id' => $po->id,
            'variant_id' => $variant->id,
            'qty_ordered' => '1000.000000',
            'qty_received' => '0.000000',
            'unit_cost_amount' => '12.000000',
            'currency' => 'MXN',
        ]);

        app(ReceiveInventory::class)->execute($workspace->id, [
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '990',
            'unit_cost_amount' => '12',
            'unit_cost_currency' => 'MXN',
            'purchase_order_line_id' => $line->id,
            'qty_expected' => '1000',
        ]);

        $line->refresh();
        $po->refresh();

        $this->assertSame('990.000000', (string) $line->qty_received);
        $this->assertSame('-10.000000', $line->varianceQty());
        $this->assertSame(SupplierPurchaseOrder::STATUS_RECEIVING, $po->status);
        $this->assertSame('990.000000', $this->onHand($workspace->id, $variant->id));
    }

    #[Test]
    public function ship_to_full_decrements_internal_oh_without_creating_full_warehouse(): void
    {
        Queue::fake();
        [$workspace, $variant, $warehouse, $connection] = $this->seedWorkspace();

        app(ReceiveInventory::class)->execute($workspace->id, [
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '1000',
            'unit_cost_amount' => '10',
            'unit_cost_currency' => 'MXN',
        ]);

        $warehouseCount = Warehouse::query()->where('workspace_id', $workspace->id)->count();

        $result = app(ShipStockToFull::class)->execute($workspace->id, [
            'variant_id' => $variant->id,
            'connection_id' => $connection->id,
            'from_warehouse_id' => $warehouse->id,
            'quantity' => '300',
            'external_inbound_id' => 'INB-300',
            'idempotency_key' => 'test-ship-1',
        ]);

        $this->assertSame(MarketplaceInbound::STATUS_PENDING, $result['inbound']->status);
        $this->assertSame('300.000000', (string) $result['inbound']->qty_sent);
        $this->assertSame('ship_to_full', $result['ledger']->movement_type);
        $this->assertSame('700.000000', $this->onHand($workspace->id, $variant->id));
        $this->assertSame(
            $warehouseCount,
            Warehouse::query()->where('workspace_id', $workspace->id)->count(),
        );
    }

    #[Test]
    public function inbound_reception_matches_pending_by_external_id_and_qty_heuristic(): void
    {
        Queue::fake();
        [$workspace, $variant, $warehouse, $connection] = $this->seedWorkspace();

        app(ReceiveInventory::class)->execute($workspace->id, [
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '500',
            'unit_cost_amount' => '10',
            'unit_cost_currency' => 'MXN',
        ]);

        $exact = app(ShipStockToFull::class)->execute($workspace->id, [
            'variant_id' => $variant->id,
            'connection_id' => $connection->id,
            'from_warehouse_id' => $warehouse->id,
            'quantity' => '100',
            'external_inbound_id' => '0001',
            'idempotency_key' => 'ship-exact',
        ])['inbound'];

        $heuristic = app(ShipStockToFull::class)->execute($workspace->id, [
            'variant_id' => $variant->id,
            'connection_id' => $connection->id,
            'from_warehouse_id' => $warehouse->id,
            'quantity' => '40',
            'idempotency_key' => 'ship-heuristic',
        ])['inbound'];

        $opExact = FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'variant_id' => $variant->id,
            'operation_type' => 'INBOUND_RECEPTION',
            'available_quantity_delta' => '100',
            'occurred_at' => now(),
            'external_references' => [['type' => 'inbound_id', 'value' => '0001']],
        ]);
        $matchedExact = app(MatchMarketplaceInbound::class)->execute($opExact);
        $this->assertNotNull($matchedExact);
        $this->assertSame($exact->id, $matchedExact->id);
        $this->assertSame(MarketplaceInbound::STATUS_MATCHED, $matchedExact->status);
        $this->assertSame($exact->id, $opExact->fresh()->marketplace_inbound_id);

        $opHeuristic = FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'variant_id' => $variant->id,
            'operation_type' => 'INBOUND_RECEPTION',
            'available_quantity_delta' => '40',
            'occurred_at' => now(),
            'external_references' => [],
        ]);
        $matchedHeuristic = app(MatchMarketplaceInbound::class)->execute($opHeuristic);
        $this->assertNotNull($matchedHeuristic);
        $this->assertSame($heuristic->id, $matchedHeuristic->id);
        $this->assertSame(MarketplaceInbound::STATUS_MATCHED, $matchedHeuristic->fresh()->status);
    }

    #[Test]
    public function sync_inbound_reception_hunts_pending_inbound(): void
    {
        Queue::fake();
        [$workspace, $variant, $warehouse, $connection] = $this->seedWorkspace();

        $credential = new EncryptedCredential(['connection_id' => $connection->id]);
        $credential->setPlainPayload([
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh',
            'user_id' => $connection->external_user_id,
            'expires_at' => now()->addHours(6)->toIso8601String(),
        ]);
        $credential->save();

        $listing = ChannelListing::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'external_item_id' => 'MLM-AE',
            'title' => 'AE Full',
            'status' => 'active',
            'logistic_type' => 'fulfillment',
            'inventory_id' => 'DEHW09303',
        ]);
        ChannelListingVariant::query()->create([
            'workspace_id' => $workspace->id,
            'channel_listing_id' => $listing->id,
            'variant_id' => $variant->id,
            'inventory_id' => 'DEHW09303',
            'sku_external' => 'SKU-AE-1',
            'status' => 'active',
            'available_quantity' => 10,
        ]);

        app(ReceiveInventory::class)->execute($workspace->id, [
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '200',
            'unit_cost_amount' => '10',
            'unit_cost_currency' => 'MXN',
        ]);
        $inbound = app(ShipStockToFull::class)->execute($workspace->id, [
            'variant_id' => $variant->id,
            'connection_id' => $connection->id,
            'from_warehouse_id' => $warehouse->id,
            'quantity' => '100',
            'external_inbound_id' => '0001',
            'idempotency_key' => 'ship-sync',
        ])['inbound'];

        Http::preventStrayRequests();
        $sellerId = (string) $connection->external_user_id;
        Http::fake(function (Request $request) use ($sellerId) {
            if (! str_contains($request->url(), '/stock/fulfillment/operations/search')) {
                return Http::response(['error' => 'unmocked'], 500);
            }

            return Http::response([
                'paging' => ['total' => 1, 'scroll' => ''],
                'results' => [[
                    'id' => 900000001,
                    'seller_id' => $sellerId,
                    'inventory_id' => 'DEHW09303',
                    'date_created' => now()->toIso8601String(),
                    'type' => 'INBOUND_RECEPTION',
                    'detail' => ['available_quantity' => 100, 'not_available_detail' => []],
                    'result' => ['total' => 100, 'available_quantity' => 100, 'not_available_quantity' => 0],
                    'external_references' => [['type' => 'inbound_id', 'value' => '0001']],
                ]],
            ], 200);
        });

        app(SyncMercadoLibreFullStockOperations::class)->execute($connection, lookbackDays: 15);

        $inbound->refresh();
        $op = FullStockOperation::query()->where('external_operation_id', '900000001')->first();
        $this->assertNotNull($op);
        $this->assertSame($inbound->id, $op->marketplace_inbound_id);
        $this->assertSame(MarketplaceInbound::STATUS_MATCHED, $inbound->status);
        $this->assertSame($op->id, $inbound->full_stock_operation_id);
    }

    #[Test]
    public function timeline_includes_a_through_e_in_order(): void
    {
        Queue::fake();
        [$workspace, $variant, $warehouse, $connection] = $this->seedWorkspace();

        $po = SupplierPurchaseOrder::factory()->create([
            'workspace_id' => $workspace->id,
            'supplier_name' => 'Proveedor X',
            'status' => SupplierPurchaseOrder::STATUS_ORDERED,
            'ordered_at' => now()->subDays(5),
        ]);
        $line = SupplierPurchaseOrderLine::factory()->create([
            'workspace_id' => $workspace->id,
            'supplier_purchase_order_id' => $po->id,
            'variant_id' => $variant->id,
            'qty_ordered' => '1000.000000',
            'unit_cost_amount' => '10.000000',
            'currency' => 'MXN',
        ]);

        app(ReceiveInventory::class)->execute($workspace->id, [
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '990',
            'unit_cost_amount' => '10',
            'unit_cost_currency' => 'MXN',
            'purchase_order_line_id' => $line->id,
            'received_at' => now()->subDays(4)->toIso8601String(),
        ]);

        $inbound = app(ShipStockToFull::class)->execute($workspace->id, [
            'variant_id' => $variant->id,
            'connection_id' => $connection->id,
            'from_warehouse_id' => $warehouse->id,
            'quantity' => '300',
            'idempotency_key' => 'ship-timeline',
            'sent_at' => now()->subDays(3)->toIso8601String(),
        ])['inbound'];

        $inboundOp = FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'variant_id' => $variant->id,
            'operation_type' => 'INBOUND_RECEPTION',
            'available_quantity_delta' => '300',
            'occurred_at' => now()->subDays(2),
            'external_references' => [],
        ]);
        app(MatchMarketplaceInbound::class)->execute($inboundOp);

        FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'variant_id' => $variant->id,
            'operation_type' => 'SALE_CONFIRMATION',
            'available_quantity_delta' => '-10',
            'occurred_at' => now()->subDay(),
        ]);

        app(ReturnStockFromFull::class)->execute($workspace->id, [
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '20',
            'idempotency_key' => 'return-timeline',
            'occurred_at' => now()->toIso8601String(),
        ]);

        $timeline = app(BuildVariantInventoryTimeline::class)->execute($workspace->id, $variant->id);
        $steps = collect($timeline)->pluck('step')->unique()->values()->all();

        $this->assertContains('A', $steps);
        $this->assertContains('B', $steps);
        $this->assertContains('C', $steps);
        $this->assertContains('C.1', $steps);
        $this->assertContains('D', $steps);
        $this->assertContains('E', $steps);

        $ordered = collect($timeline)->pluck('step')->all();
        $firstA = array_search('A', $ordered, true);
        $firstB = array_search('B', $ordered, true);
        $firstC = array_search('C', $ordered, true);
        $firstC1 = array_search('C.1', $ordered, true);
        $firstD = array_search('D', $ordered, true);
        $firstE = array_search('E', $ordered, true);

        $this->assertTrue($firstA < $firstB);
        $this->assertTrue($firstB < $firstC);
        $this->assertTrue($firstC < $firstC1);
        $this->assertTrue($firstC1 < $firstD);
        $this->assertTrue($firstD < $firstE);
    }

    #[Test]
    public function return_from_full_increments_internal_oh(): void
    {
        Queue::fake();
        [$workspace, $variant, $warehouse, $connection] = $this->seedWorkspace();

        app(ReceiveInventory::class)->execute($workspace->id, [
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '100',
            'unit_cost_amount' => '10',
            'unit_cost_currency' => 'MXN',
        ]);
        app(ShipStockToFull::class)->execute($workspace->id, [
            'variant_id' => $variant->id,
            'connection_id' => $connection->id,
            'from_warehouse_id' => $warehouse->id,
            'quantity' => '40',
            'idempotency_key' => 'ship-return',
        ]);

        app(ReturnStockFromFull::class)->execute($workspace->id, [
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '15',
            'idempotency_key' => 'return-15',
        ]);

        $this->assertSame('75.000000', $this->onHand($workspace->id, $variant->id));
    }

    #[Test]
    public function variant_id_filters_ledger_full_and_receipts(): void
    {
        Queue::fake();
        [$workspace, $variant, $warehouse, $connection] = $this->seedWorkspace();
        $other = Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $variant->product_id,
            'sku' => 'SKU-AE-2',
        ]);

        app(ReceiveInventory::class)->execute($workspace->id, [
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '10',
            'unit_cost_amount' => '1',
            'unit_cost_currency' => 'MXN',
        ]);
        app(ReceiveInventory::class)->execute($workspace->id, [
            'variant_id' => $other->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '8',
            'unit_cost_amount' => '1',
            'unit_cost_currency' => 'MXN',
        ]);

        FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'variant_id' => $variant->id,
            'operation_type' => 'SALE_CONFIRMATION',
        ]);
        FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'variant_id' => $other->id,
            'operation_type' => 'SALE_CONFIRMATION',
        ]);

        $this->get(route('inventory.ledger.index', ['variant_id' => $variant->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Inventory/Ledger')
                ->where('filters.variant_id', $variant->id)
                ->has('entries.data', 1));

        $this->get(route('inventory.receipts.index', ['variant_id' => $variant->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Inventory/Receipts')
                ->where('filters.variant_id', $variant->id)
                ->has('receipts.data', 1));

        $this->get(route('inventory.full-operations.index', ['variant_id' => $variant->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Inventory/FullOperations')
                ->where('filters.variant_id', $variant->id));
    }
}
