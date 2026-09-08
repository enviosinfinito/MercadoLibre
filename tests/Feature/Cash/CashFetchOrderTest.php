<?php

namespace Tests\Feature\Cash;

use App\Models\Connection;
use App\Models\ConnectionSyncProfile;
use App\Models\EncryptedCredential;
use App\Models\MarketplacePayment;
use App\Models\Order;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CashFetchOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    #[Test]
    public function fetch_order_pulls_missing_order_from_mercadolibre(): void
    {
        [$user, $workspace, $connection] = $this->memberWithConnection();

        Http::preventStrayRequests();
        Http::fake([
            '*/orders/2000017830926822*' => Http::response([
                'id' => 2000017830926822,
                'status' => 'paid',
                'currency_id' => 'MXN',
                'total_amount' => 1208.08,
                'date_created' => '2026-08-08T20:34:42.000-00:00',
                'date_closed' => '2026-08-08T20:34:42.000-00:00',
                'buyer' => ['id' => 555],
                'payments' => [
                    [
                        'id' => 171878421023,
                        'status' => 'approved',
                        'transaction_amount' => 1500,
                        'net_received_amount' => 1208.08,
                        'currency_id' => 'MXN',
                    ],
                ],
                'order_items' => [
                    [
                        'quantity' => 1,
                        'unit_price' => 1500,
                        'sale_fee' => 100,
                        'item' => [
                            'id' => 'MLM1',
                            'title' => 'Demo',
                            'seller_sku' => 'SKU-1',
                        ],
                    ],
                ],
            ], 200),
            '*/collections/171878421023*' => Http::response([
                'id' => 171878421023,
                'status' => 'approved',
                'transaction_amount' => 1500,
                'net_received_amount' => 1208.08,
                'currency_id' => 'MXN',
                'date_approved' => '2026-08-08T20:34:42.000-00:00',
            ], 200),
        ]);

        $this->assertDatabaseMissing('orders', [
            'connection_id' => $connection->id,
            'external_order_id' => '2000017830926822',
        ]);

        $this->actingAs($user)
            ->withSession(['workspace_id' => $workspace->id])
            ->postJson(route('finance.cash.fetch-order'), [
                'connection_id' => $connection->id,
                'external_order_id' => '2000017830926822',
            ])
            ->assertOk()
            ->assertJsonPath('external_order_id', '2000017830926822')
            ->assertJsonPath('status', 'paid');

        $order = Order::query()
            ->where('connection_id', $connection->id)
            ->where('external_order_id', '2000017830926822')
            ->first();
        $this->assertNotNull($order);
    }

    #[Test]
    public function fetch_order_rejects_shipping_ids(): void
    {
        [$user, $workspace, $connection] = $this->memberWithConnection();

        $this->actingAs($user)
            ->withSession(['workspace_id' => $workspace->id])
            ->postJson(route('finance.cash.fetch-order'), [
                'connection_id' => $connection->id,
                'external_order_id' => '47724611357',
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Ese ID es un envío del comprador, no una orden de Mercado Libre.']);
    }

    #[Test]
    public function fetch_order_does_not_reject_twelve_digit_payment_id_as_shipping(): void
    {
        [$user, $workspace, $connection] = $this->memberWithConnection();
        $mlOrderId = '2000017825798198';

        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => $mlOrderId,
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '200.000000',
            'paid_at' => now()->subDays(10),
            'ordered_at' => now()->subDays(10),
        ]);

        MarketplacePayment::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'external_payment_id' => '172734291880',
            'currency_code' => 'MXN',
            'transaction_amount' => '200.000000',
            'net_received_amount' => '133.170000',
            'status' => 'in_mediation',
            'paid_at' => now()->subDays(10),
            'reconciliation_status' => 'balanced',
            'provenance' => 'collections',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            '*/orders/2000017825798198*' => Http::response([
                'id' => 2000017825798198,
                'status' => 'paid',
                'currency_id' => 'MXN',
                'total_amount' => 200,
                'date_created' => '2026-08-01T10:00:00.000-00:00',
                'date_closed' => '2026-08-01T10:00:00.000-00:00',
                'buyer' => ['id' => 555],
                'payments' => [
                    [
                        'id' => 172734291880,
                        'status' => 'in_mediation',
                        'transaction_amount' => 200,
                        'net_received_amount' => 133.17,
                        'currency_id' => 'MXN',
                    ],
                ],
                'order_items' => [
                    [
                        'quantity' => 1,
                        'unit_price' => 200,
                        'sale_fee' => 50,
                        'item' => [
                            'id' => 'MLM1',
                            'title' => 'Demo',
                            'seller_sku' => 'SKU-1',
                        ],
                    ],
                ],
            ], 200),
            '*/collections/172734291880*' => Http::response([
                'id' => 172734291880,
                'status' => 'in_mediation',
                'transaction_amount' => 200,
                'net_received_amount' => 133.17,
                'currency_id' => 'MXN',
                'date_approved' => '2026-08-01T10:00:00.000-00:00',
            ], 200),
        ]);

        $this->actingAs($user)
            ->withSession(['workspace_id' => $workspace->id])
            ->postJson(route('finance.cash.fetch-order'), [
                'connection_id' => $connection->id,
                'external_order_id' => '172734291880',
            ])
            ->assertOk()
            ->assertJsonPath('external_order_id', $mlOrderId);
    }

    #[Test]
    public function fetch_order_is_404_for_other_workspace_connection(): void
    {
        [$user] = $this->memberWithConnection();
        $otherWorkspace = Workspace::factory()->create();
        $otherConnection = Connection::factory()->create([
            'workspace_id' => $otherWorkspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->withSession(['workspace_id' => Workspace::query()->whereKeyNot($otherWorkspace->id)->value('id')])
            ->postJson(route('finance.cash.fetch-order'), [
                'connection_id' => $otherConnection->id,
                'external_order_id' => '2000017830926822',
            ])
            ->assertNotFound();
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Connection}
     */
    private function memberWithConnection(): array
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
            'external_user_id' => '1486512189',
            'status' => 'active',
            'token_generation' => 1,
        ]);

        $credential = new EncryptedCredential(['connection_id' => $connection->id]);
        $credential->setPlainPayload([
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh',
            'user_id' => '1486512189',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]);
        $credential->save();

        foreach (['orders', 'shipments'] as $key) {
            ConnectionSyncProfile::query()->create([
                'workspace_id' => $workspace->id,
                'connection_id' => $connection->id,
                'resource_key' => $key,
                'enabled' => true,
                'config' => [
                    'include' => $key === 'orders'
                        ? ['raw_snapshot' => true, 'lines' => true, 'buyer' => true]
                        : ['raw_snapshot' => true],
                ],
            ]);
        }

        return [$user, $workspace, $connection];
    }
}
