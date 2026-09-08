<?php

namespace Tests\Feature;

use App\Domain\Inventory\Actions\AdjustStock;
use App\Domain\Inventory\Actions\FulfillReservation;
use App\Domain\Inventory\Actions\ReceiveInventory;
use App\Domain\Inventory\Actions\ReleaseStock;
use App\Domain\Inventory\Actions\ReserveStock;
use App\Domain\Inventory\Actions\TransferStock;
use App\Models\Connection;
use App\Models\InventoryBalance;
use App\Models\InventoryLedger;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Models\Warehouse;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InventoryHubAndDomainTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Workspace, 1: Variant, 2: Warehouse}
     */
    private function seedCatalog(): array
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
            'sku' => 'SKU-INV-1',
        ]);

        return [$workspace, $variant, $warehouse];
    }

    #[Test]
    public function stock_warehouses_ledger_and_receipts_pages_load(): void
    {
        [$workspace, $variant] = $this->seedCatalog();

        app(ReceiveInventory::class)->execute($workspace->id, [
            'variant_id' => $variant->id,
            'quantity' => 10,
            'unit_cost_amount' => 5,
            'unit_cost_currency' => 'MXN',
        ]);

        $this->get(route('stock.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Stock/Index')
                ->has('rows.data')
                ->has('warehouses')
                ->has('filters'));

        $this->get(route('stock.show', $variant))
            ->assertOk()
            ->assertJsonPath('variant.sku', 'SKU-INV-1')
            ->assertJsonStructure(['variant', 'reservations', 'ledger', 'cost_layers', 'warehouses', 'channel_stock_locations', 'timeline']);

        $this->get(route('inventory.warehouses.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inventory/Warehouses'));

        $this->get(route('inventory.ledger.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inventory/Ledger'));

        $this->get(route('inventory.receipts.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inventory/Receipts'));
    }

    #[Test]
    public function adjust_transfer_release_and_fulfill_update_balances(): void
    {
        Queue::fake();

        [$workspace, $variant, $warehouse] = $this->seedCatalog();

        $secondary = Warehouse::factory()->create([
            'workspace_id' => $workspace->id,
            'code' => 'SEC',
            'is_default' => false,
        ]);

        app(ReceiveInventory::class)->execute($workspace->id, [
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 20,
            'unit_cost_amount' => 1,
            'unit_cost_currency' => 'MXN',
        ]);

        app(AdjustStock::class)->execute($workspace->id, [
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity_delta' => -2,
            'idempotency_key' => 'test-adjust-1',
        ]);

        app(TransferStock::class)->execute($workspace->id, [
            'variant_id' => $variant->id,
            'from_warehouse_id' => $warehouse->id,
            'to_warehouse_id' => $secondary->id,
            'quantity' => 5,
            'idempotency_key' => 'test-transfer-1',
        ]);

        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);

        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-INV',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '10',
            'ordered_at' => now(),
        ]);

        $line = OrderLine::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'variant_id' => $variant->id,
            'sku' => $variant->sku,
            'quantity' => 3,
            'unit_price_amount' => '10',
            'currency_code' => 'MXN',
            'line_total_amount' => '30',
        ]);

        $reservation = app(ReserveStock::class)->execute($line);
        $this->assertNotNull($reservation);
        $this->assertSame('active', $reservation->status);

        app(ReleaseStock::class)->execute($reservation);
        $this->assertSame('released', $reservation->fresh()->status);

        $line2 = OrderLine::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'variant_id' => $variant->id,
            'sku' => $variant->sku,
            'quantity' => 2,
            'unit_price_amount' => '10',
            'currency_code' => 'MXN',
            'line_total_amount' => '20',
        ]);

        $reservation2 = app(ReserveStock::class)->execute($line2);
        app(FulfillReservation::class)->execute($reservation2);
        $this->assertSame('fulfilled', $reservation2->fresh()->status);

        $this->assertTrue(
            InventoryLedger::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('movement_type', ['adjust', 'transfer_out', 'transfer_in', 'release', 'fulfill'])
                ->exists()
        );

        $balances = InventoryBalance::query()
            ->where('workspace_id', $workspace->id)
            ->get();
        $this->assertNotEmpty($balances);
    }

    #[Test]
    public function warehouse_can_be_created_via_http(): void
    {
        $this->seedCatalog();

        $this->withHeaders(['Sec-Fetch-Site' => 'same-origin'])
            ->post(route('inventory.warehouses.store'), [
                'code' => 'WH2',
                'name' => 'Bodega 2',
                'is_default' => false,
            ])
            ->assertRedirect(route('inventory.warehouses.index'));

        $this->assertDatabaseHas('warehouses', ['code' => 'WH2', 'name' => 'Bodega 2']);
    }
}
