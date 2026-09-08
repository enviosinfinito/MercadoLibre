<?php

namespace Tests\Feature;

use App\Domain\Fulfillment\Actions\UpsertCanonicalShipment;
use App\Jobs\ProcessCanonicalShipmentJob;
use App\Models\Connection;
use App\Models\ConnectionSyncProfile;
use App\Models\EncryptedCredential;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderLine;
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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderTimelineDetailSlidesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Connection, 3: Order}
     */
    private function actingMemberWithOrder(): array
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

        ConnectionSyncProfile::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'resource_key' => 'shipments',
            'enabled' => true,
            'config' => ['include' => ['raw_snapshot' => true]],
        ]);

        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => '2000017726922752',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '100',
            'ordered_at' => now()->subDay(),
            'paid_at' => now()->subDay(),
            'meta' => ['shipping' => ['id' => 400001]],
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$user, $workspace, $connection, $order];
    }

    #[Test]
    public function process_canonical_shipment_persists_rich_meta(): void
    {
        [, $workspace, $connection, $order] = $this->actingMemberWithOrder();

        $snapshot = RawResourceSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'resource_type' => 'shipment',
            'external_id' => '400001',
            'payload' => [
                'id' => 400001,
                'order_id' => (int) $order->external_order_id,
                'status' => 'delivered',
                'substatus' => null,
                'mode' => 'me2',
                'logistic_type' => 'drop_off',
                'tracking_number' => 'AINGU2FIYVIAFOCP56VLI5WIII',
                'tracking_method' => 'Estafeta',
                'service_id' => 22,
                'receiver_id' => 529340135,
                'base_cost' => 65.5,
                'date_created' => '2026-08-03T09:00:00.000-00:00',
                'last_updated' => '2026-08-03T15:23:00.000-00:00',
                'status_history' => [
                    'date_shipped' => '2026-08-03T09:29:00.000-00:00',
                    'date_delivered' => '2026-08-03T15:23:00.000-00:00',
                    'date_ready_to_ship' => '2026-08-03T08:00:00.000-00:00',
                ],
                'receiver_address' => [
                    'address_line' => 'Calle Falsa 123',
                    'city' => ['name' => 'Ciudad de México'],
                    'state' => ['name' => 'Ciudad de México'],
                    'zip_code' => '01000',
                    'receiver_name' => 'Juan Pérez',
                ],
            ],
            'checksum' => 'meta-ship',
            'fetched_at' => now(),
        ]);

        (new ProcessCanonicalShipmentJob(
            $workspace->id,
            $connection->id,
            (int) $snapshot->id,
        ))->handle();

        $shipment = Shipment::query()
            ->where('external_shipment_id', '400001')
            ->firstOrFail();

        $this->assertSame('delivered', $shipment->status);
        $this->assertSame('Estafeta', $shipment->carrier);
        $this->assertSame('AINGU2FIYVIAFOCP56VLI5WIII', $shipment->tracking_number);
        $this->assertSame('drop_off', $shipment->meta['logistic_type'] ?? null);
        $this->assertSame('529340135', $shipment->meta['receiver_id'] ?? null);
        $this->assertSame('Calle Falsa 123', $shipment->meta['receiver_address']['address_line'] ?? null);
        $this->assertNotEmpty($shipment->meta['status_history'] ?? null);

        $response = $this->getJson(route('shipments.show', $shipment));
        $response->assertOk();
        $response->assertJsonPath('shipment.meta.receiver_address.receiver_name', 'Juan Pérez');
        $response->assertJsonPath('shipment.tracking_number', 'AINGU2FIYVIAFOCP56VLI5WIII');
    }

    #[Test]
    public function orders_reservations_lists_workspace_reservations(): void
    {
        [, $workspace, $connection, $order] = $this->actingMemberWithOrder();

        $warehouse = Warehouse::factory()->default()->create([
            'workspace_id' => $workspace->id,
            'is_active' => true,
        ]);

        $variant = Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'sku' => 'RB0101013M01280101',
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
            'sku' => 'RB0101013M01280101',
            'title' => 'iPhone',
            'quantity' => '1',
            'unit_price_amount' => '100',
            'currency_code' => 'MXN',
            'line_total_amount' => '100',
            'match_status' => 'matched',
        ]);

        Reservation::query()->create([
            'workspace_id' => $workspace->id,
            'inventory_item_id' => $item->id,
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'order_id' => $order->id,
            'order_line_id' => $line->id,
            'quantity' => '1',
            'status' => 'reserved',
            'idempotency_key' => 'reserve:order_line:'.$line->id,
            'reserved_at' => now(),
        ]);

        $response = $this->getJson(route('orders.reservations', $order));
        $response->assertOk();
        $response->assertJsonPath('order.id', $order->id);
        $response->assertJsonCount(1, 'reservations');
        $response->assertJsonPath('reservations.0.order_line.sku', 'RB0101013M01280101');
        $response->assertJsonPath('reservations.0.warehouse.id', $warehouse->id);
    }

    #[Test]
    public function orders_reservations_returns_404_for_other_workspace(): void
    {
        [, , , $order] = $this->actingMemberWithOrder();

        $otherUser = User::factory()->create();
        $otherWorkspace = Workspace::factory()->create();
        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $otherWorkspace->id,
            'user_id' => $otherUser->id,
        ]);

        $this->actingAs($otherUser)->withSession(['workspace_id' => $otherWorkspace->id]);

        $this->getJson(route('orders.reservations', $order))->assertNotFound();
    }

    #[Test]
    public function build_meta_from_provider_payload_keeps_expected_keys(): void
    {
        $meta = app(UpsertCanonicalShipment::class)->buildMetaFromProviderPayload([
            'substatus' => 'out_for_delivery',
            'mode' => 'me2',
            'tracking_method' => 'DHL',
            'receiver_id' => 99,
            'status_history' => ['date_shipped' => '2026-01-01T00:00:00.000Z'],
            'receiver_address' => ['city' => ['name' => 'CDMX']],
            'noise' => 'ignored-unless-whitelisted',
        ]);

        $this->assertSame('out_for_delivery', $meta['substatus']);
        $this->assertSame('me2', $meta['mode']);
        $this->assertArrayNotHasKey('noise', $meta);
        $this->assertArrayHasKey('status_history', $meta);
        $this->assertArrayHasKey('receiver_address', $meta);
    }
}
