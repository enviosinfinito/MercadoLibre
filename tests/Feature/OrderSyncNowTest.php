<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ConnectionSyncProfile;
use App\Models\EncryptedCredential;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderSyncNowTest extends TestCase
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
    private function actingMemberWithPaidOrder(): array
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

        foreach (['orders', 'shipments', 'order_fees'] as $key) {
            ConnectionSyncProfile::query()->create([
                'workspace_id' => $workspace->id,
                'connection_id' => $connection->id,
                'resource_key' => $key,
                'enabled' => $key !== 'order_fees',
                'config' => [
                    'include' => match ($key) {
                        'orders' => ['raw_snapshot' => true, 'lines' => true, 'buyer' => true],
                        default => ['raw_snapshot' => true],
                    },
                ],
            ]);
        }

        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => '2000017726922752',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '199.00',
            'ordered_at' => now()->subDays(4),
            'paid_at' => now()->subDays(4),
            'meta' => ['shipping' => ['id' => 400001]],
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$user, $workspace, $connection, $order];
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleOrderPayload(): array
    {
        return [
            'id' => 2000017726922752,
            'status' => 'paid',
            'currency_id' => 'MXN',
            'total_amount' => 199,
            'date_created' => '2026-08-03T03:17:00.000-00:00',
            'date_closed' => '2026-08-03T03:17:00.000-00:00',
            'buyer' => ['id' => 555],
            'shipping' => ['id' => 400001],
            'order_items' => [
                [
                    'quantity' => 1,
                    'unit_price' => 199,
                    'item' => [
                        'id' => 'MLM999',
                        'title' => 'Producto demo',
                        'seller_sku' => 'SKU-1',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleShipmentPayload(): array
    {
        return [
            'id' => 400001,
            'order_id' => 2000017726922752,
            'status' => 'delivered',
            'tracking_number' => 'TRACK-999',
            'tracking_method' => 'Estafeta',
            'status_history' => [
                'date_shipped' => '2026-08-04T10:00:00.000-00:00',
                'date_delivered' => '2026-08-05T15:30:00.000-00:00',
            ],
        ];
    }

    #[Test]
    public function sync_now_refreshes_order_and_shipment_from_mercadolibre(): void
    {
        [, , , $order] = $this->actingMemberWithPaidOrder();

        Http::preventStrayRequests();
        Http::fake([
            '*/orders/2000017726922752*' => Http::response($this->sampleOrderPayload(), 200),
            '*/shipments/400001*' => Http::response($this->sampleShipmentPayload(), 200),
        ]);

        $response = $this->postJson(route('orders.sync-now', $order));

        $response->assertOk();
        $timeline = collect($response->json('timeline'))->keyBy('key');
        $this->assertTrue($timeline['paid']['done']);
        $this->assertTrue($timeline['shipped']['done']);
        $this->assertTrue($timeline['delivered']['done']);
        $this->assertSame('delivered', $response->json('shipment.status'));
        $this->assertSame('TRACK-999', $response->json('shipment.tracking_number'));

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'external_shipment_id' => '400001',
            'status' => 'delivered',
        ]);

        $order->refresh();
        $this->assertSame('delivered', $order->status);
    }

    #[Test]
    public function sync_now_returns_404_for_other_workspace(): void
    {
        [, , , $order] = $this->actingMemberWithPaidOrder();

        $otherUser = User::factory()->create();
        $otherWorkspace = Workspace::factory()->create();
        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $otherWorkspace->id,
            'user_id' => $otherUser->id,
        ]);

        $this->actingAs($otherUser)->withSession(['workspace_id' => $otherWorkspace->id]);

        $this->postJson(route('orders.sync-now', $order))->assertNotFound();
    }

    #[Test]
    public function sync_now_returns_422_when_orders_sync_disabled(): void
    {
        [, , $connection, $order] = $this->actingMemberWithPaidOrder();

        ConnectionSyncProfile::query()
            ->where('connection_id', $connection->id)
            ->where('resource_key', 'orders')
            ->update(['enabled' => false]);

        $response = $this->postJson(route('orders.sync-now', $order));

        $response->assertStatus(422);
        $this->assertStringContainsString('deshabilitada', (string) $response->json('message'));
        $this->assertSame(0, Shipment::query()->count());
    }
}
