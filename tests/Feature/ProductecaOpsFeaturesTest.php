<?php

namespace Tests\Feature;

use App\Domain\Catalog\Actions\UpsertChannelPrice;
use App\Domain\Outbound\Actions\EnqueueChannelStockSync;
use App\Jobs\PushOutboundCommandJob;
use App\Models\Alert;
use App\Models\ChannelListing;
use App\Models\ChannelListingVariant;
use App\Models\Connection;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OutboundCommand;
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

class ProductecaOpsFeaturesTest extends TestCase
{
    use RefreshDatabase;

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

        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$user, $workspace, $connection];
    }

    #[Test]
    public function orders_index_filters_by_status_and_connection(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithConnection();

        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-PAID',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '100',
            'ordered_at' => now(),
        ]);

        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-PEND',
            'status' => 'pending',
            'currency_code' => 'MXN',
            'total_amount' => '50',
            'ordered_at' => now(),
        ]);

        $this->get(route('orders.index', ['status' => 'paid']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Index')
                ->has('orders.data', 1)
                ->where('orders.data.0.external_order_id', 'ORD-PAID'));
    }

    #[Test]
    public function enqueue_channel_stock_sync_creates_outbound_commands(): void
    {
        Queue::fake();

        [, $workspace, $connection] = $this->actingMemberWithConnection();

        Warehouse::factory()->create([
            'workspace_id' => $workspace->id,
            'is_default' => true,
            'is_active' => true,
        ]);

        $product = Product::factory()->create(['workspace_id' => $workspace->id]);
        $variant = Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
        ]);

        $item = InventoryItem::query()->create([
            'workspace_id' => $workspace->id,
            'variant_id' => $variant->id,
            'warehouse_id' => Warehouse::query()->where('workspace_id', $workspace->id)->value('id'),
        ]);

        InventoryBalance::query()->create([
            'workspace_id' => $workspace->id,
            'inventory_item_id' => $item->id,
            'quantity_on_hand' => '10',
            'quantity_reserved' => '0',
            'quantity_available' => '10',
        ]);

        $listing = ChannelListing::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'external_item_id' => 'MLM123',
            'title' => 'Test',
            'status' => 'active',
        ]);

        ChannelListingVariant::query()->create([
            'workspace_id' => $workspace->id,
            'channel_listing_id' => $listing->id,
            'variant_id' => $variant->id,
            'status' => 'active',
        ]);

        $enqueued = app(EnqueueChannelStockSync::class)->execute($workspace->id, $variant->id);

        $this->assertSame(1, $enqueued);
        $this->assertDatabaseHas('outbound_commands', [
            'workspace_id' => $workspace->id,
            'command_type' => 'stock_update',
            'status' => 'pending',
        ]);
        Queue::assertPushed(PushOutboundCommandJob::class);
    }

    #[Test]
    public function upsert_channel_price_enqueues_price_update(): void
    {
        Queue::fake();

        [, $workspace, $connection] = $this->actingMemberWithConnection();

        $product = Product::factory()->create(['workspace_id' => $workspace->id]);
        $variant = Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'base_price_amount' => '100',
            'base_price_currency' => 'MXN',
        ]);

        $listing = ChannelListing::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'external_item_id' => 'MLM999',
            'title' => 'Priced',
            'status' => 'active',
        ]);

        $clv = ChannelListingVariant::query()->create([
            'workspace_id' => $workspace->id,
            'channel_listing_id' => $listing->id,
            'variant_id' => $variant->id,
            'status' => 'active',
        ]);

        app(UpsertChannelPrice::class)->execute($clv, [
            'price_amount' => '120',
            'currency_code' => 'MXN',
            'sync' => true,
        ]);

        $this->assertDatabaseHas('channel_listing_variants', [
            'id' => $clv->id,
            'price_amount' => '120.000000',
        ]);
        $this->assertDatabaseHas('outbound_commands', [
            'command_type' => 'price_update',
            'workspace_id' => $workspace->id,
        ]);
        Queue::assertPushed(PushOutboundCommandJob::class);
    }

    #[Test]
    public function monitoring_index_returns_kpis_and_can_acknowledge_alerts(): void
    {
        [, $workspace] = $this->actingMemberWithConnection();

        $alert = Alert::query()->create([
            'workspace_id' => $workspace->id,
            'severity' => 'warning',
            'title' => 'Low stock',
            'body' => 'SKU low',
            'status' => 'open',
            'triggered_at' => now(),
        ]);

        $this->get(route('monitoring.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Monitoring/Index')
                ->has('kpis')
                ->has('integrations')
                ->has('alerts', 1));

        $this->withHeaders(['Sec-Fetch-Site' => 'same-origin'])
            ->post(route('monitoring.alerts.acknowledge', $alert))
            ->assertRedirect();

        $this->assertNotNull($alert->fresh()->acknowledged_at);
    }

    #[Test]
    public function stock_prices_publications_and_dashboard_pages_load(): void
    {
        $this->actingMemberWithConnection();

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('stock.index'))->assertOk();
        $this->get(route('prices.index'))->assertOk();
        $this->get(route('publications.index'))->assertOk();
    }

    #[Test]
    public function publications_bulk_pause_enqueues_listing_status(): void
    {
        Queue::fake();

        [, $workspace, $connection] = $this->actingMemberWithConnection();

        $listing = ChannelListing::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'external_item_id' => 'MLM-BULK',
            'title' => 'Bulk',
            'status' => 'active',
        ]);

        $this->withHeaders(['Sec-Fetch-Site' => 'same-origin'])
            ->post(route('publications.bulk'), [
                'ids' => [$listing->id],
                'action' => 'pause',
            ])->assertRedirect();

        $this->assertSame('paused', $listing->fresh()->status);
        $this->assertDatabaseHas('outbound_commands', [
            'command_type' => 'listing_status',
            'workspace_id' => $workspace->id,
        ]);
        Queue::assertPushed(PushOutboundCommandJob::class);
    }
}
