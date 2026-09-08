<?php

namespace Tests\Feature;

use App\Jobs\BootstrapMercadoLibreOrdersJob;
use App\Jobs\ProcessCanonicalOrderJob;
use App\Models\Connection;
use App\Models\ConnectionSyncProfile;
use App\Models\EncryptedCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\RawResourceSnapshot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Variant;
use App\Models\Warehouse;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BootstrapMercadoLibreOrdersTest extends TestCase
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
    private function actingMemberWithMeliConnection(): array
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
     * @return array<string, mixed>
     */
    private function sampleOrderPayload(string $orderId = '200000123', string $sku = 'ORD-SKU-1'): array
    {
        return [
            'id' => $orderId,
            'status' => 'paid',
            'currency_id' => 'MXN',
            'total_amount' => 150,
            'date_created' => '2026-06-01T12:00:00.000-00:00',
            'date_closed' => '2026-06-01T12:05:00.000-00:00',
            'buyer' => ['id' => 555],
            'order_items' => [
                [
                    'quantity' => 2,
                    'unit_price' => 75,
                    'item' => [
                        'id' => 'MLM999',
                        'title' => 'Producto demo',
                        'seller_sku' => $sku,
                    ],
                ],
            ],
        ];
    }

    private function fakeOrdersSearch(?array $orderPayload = null): void
    {
        $orderPayload ??= $this->sampleOrderPayload();
        $orderId = (string) ($orderPayload['id'] ?? '200000123');

        Http::preventStrayRequests();
        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($orderPayload, $orderId) {
            $url = $request->url();

            if (str_contains($url, '/orders/search')) {
                return Http::response([
                    'results' => [$orderPayload],
                    'paging' => ['total' => 1, 'offset' => 0, 'limit' => 50],
                ], 200);
            }

            if (str_contains($url, '/orders/'.$orderId) && ! str_contains($url, 'search')) {
                return Http::response($orderPayload, 200);
            }

            if (str_contains($url, '/messages/packs/')) {
                return Http::response([
                    'paging' => ['total' => 0, 'limit' => 50, 'offset' => 0],
                    'messages' => [],
                ], 200);
            }

            return Http::response(['error' => 'unmocked', 'url' => $url], 500);
        });
    }

    #[Test]
    public function pull_orders_returns_search_results(): void
    {
        $this->actingMemberWithMeliConnection();
        $this->fakeOrdersSearch();

        $connector = app(\App\Integrations\MercadoLibre\Connector\MercadoLibreConnector::class);
        $result = $connector->pull(new \App\Integrations\Contracts\Dto\PullRequest(
            resource: 'orders',
            cursor: [],
            options: [
                'access_token' => 'test-access-token',
                'user_id' => '112184176',
                'lookback_days' => 90,
            ],
        ));

        $this->assertCount(1, $result->items);
        $this->assertSame('200000123', (string) $result->items[0]['id']);
        $this->assertTrue((bool) ($result->nextCursor['done'] ?? false));
    }

    #[Test]
    public function bootstrap_job_fetches_full_order_and_applies_inventory(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();
        $this->fakeOrdersSearch();

        Warehouse::factory()->default()->create([
            'workspace_id' => $workspace->id,
            'is_active' => true,
        ]);

        $product = Product::factory()->create(['workspace_id' => $workspace->id]);
        Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'sku' => 'ORD-SKU-1',
        ]);

        (new BootstrapMercadoLibreOrdersJob($workspace->id, $connection->id))->handle();

        $this->assertDatabaseHas('orders', [
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => '200000123',
            'status' => 'paid',
        ]);

        $this->assertSame(1, RawResourceSnapshot::query()
            ->where('connection_id', $connection->id)
            ->where('resource_type', 'order')
            ->where('external_id', '200000123')
            ->count());

        $order = Order::query()->where('external_order_id', '200000123')->firstOrFail();
        $this->assertGreaterThan(0, Reservation::query()
            ->where('workspace_id', $workspace->id)
            ->where('order_id', $order->id)
            ->count());

        $connection->refresh();
        $this->assertSame('fresh', $connection->freshness_status);
        $this->assertNotNull($connection->last_synced_at);
    }

    #[Test]
    public function bootstrap_job_syncs_order_messages_when_enabled(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();

        $payload = $this->sampleOrderPayload();
        Http::preventStrayRequests();
        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($payload) {
            $url = $request->url();

            if (str_contains($url, '/orders/search')) {
                return Http::response([
                    'results' => [$payload],
                    'paging' => ['total' => 1, 'offset' => 0, 'limit' => 50],
                ], 200);
            }

            if (str_contains($url, '/orders/200000123') && ! str_contains($url, 'search')) {
                return Http::response($payload, 200);
            }

            if (str_contains($url, '/messages/packs/200000123/')) {
                return Http::response([
                    'paging' => ['total' => 1, 'limit' => 50, 'offset' => 0],
                    'messages' => [
                        [
                            'id' => 'msg-boot-1',
                            'from' => ['user_id' => 555],
                            'to' => ['user_id' => 112184176],
                            'status' => 'available',
                            'text' => 'Mensaje en bootstrap',
                            'message_date' => [
                                'created' => '2026-06-01T13:00:00.000Z',
                                'read' => null,
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'unmocked', 'url' => $url], 500);
        });

        (new BootstrapMercadoLibreOrdersJob($workspace->id, $connection->id))->handle();

        $order = Order::query()->where('external_order_id', '200000123')->firstOrFail();
        $this->assertDatabaseHas('order_messages', [
            'order_id' => $order->id,
            'external_message_id' => 'msg-boot-1',
            'text' => 'Mensaje en bootstrap',
        ]);
    }

    #[Test]
    public function process_canonical_order_reserves_stock_by_default(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();

        Warehouse::factory()->default()->create([
            'workspace_id' => $workspace->id,
            'is_active' => true,
        ]);

        $product = Product::factory()->create(['workspace_id' => $workspace->id]);
        Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'sku' => 'ORD-SKU-1',
        ]);

        $payload = $this->sampleOrderPayload('200000999');
        $snapshot = RawResourceSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'resource_type' => 'order',
            'external_id' => '200000999',
            'payload' => $payload,
            'checksum' => 'abc',
            'fetched_at' => now(),
        ]);

        (new ProcessCanonicalOrderJob(
            $workspace->id,
            $connection->id,
            (int) $snapshot->id,
        ))->handle();

        $this->assertDatabaseHas('orders', [
            'external_order_id' => '200000999',
        ]);

        $order = Order::query()->where('external_order_id', '200000999')->firstOrFail();
        $this->assertGreaterThan(0, Reservation::query()
            ->where('workspace_id', $workspace->id)
            ->where('order_id', $order->id)
            ->count());
    }

    #[Test]
    public function sync_now_dispatches_orders_bootstrap_job(): void
    {
        Queue::fake();

        [, , $connection] = $this->actingMemberWithMeliConnection();

        ConnectionSyncProfile::query()->create([
            'workspace_id' => $connection->workspace_id,
            'connection_id' => $connection->id,
            'resource_key' => 'orders',
            'enabled' => true,
            'config' => [
                'lookback_days' => 90,
                'include' => ['raw_snapshot' => true, 'lines' => true, 'buyer' => true],
            ],
        ]);

        $this->post(route('connections.sync-now', $connection), [
            'resource_key' => 'orders',
        ])->assertRedirect(route('connections.show', $connection));

        Queue::assertPushed(BootstrapMercadoLibreOrdersJob::class, function ($job) use ($connection) {
            return $job->connectionId === $connection->id
                && $job->workspaceId === $connection->workspace_id;
        });
    }
}
