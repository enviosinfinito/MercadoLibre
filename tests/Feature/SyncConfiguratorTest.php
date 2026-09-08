<?php

namespace Tests\Feature;

use App\Jobs\BootstrapMercadoLibreListingsJob;
use App\Jobs\BootstrapMercadoLibreOrdersJob;
use App\Models\ChannelListing;
use App\Models\ChannelListingVariant;
use App\Models\Connection;
use App\Models\ConnectionSyncProfile;
use App\Models\EncryptedCredential;
use App\Models\RawResourceSnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SyncConfiguratorTest extends TestCase
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
        $workspace = Workspace::factory()->create();

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

    #[Test]
    public function show_seeds_default_profiles_and_catalog(): void
    {
        [, , $connection] = $this->actingMemberWithMeliConnection();

        $this->get(route('connections.show', $connection))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Connections/Show')
                ->has('catalog')
                ->has('profiles')
                ->where('connection.id', $connection->id));

        $this->assertDatabaseHas('connection_sync_profiles', [
            'connection_id' => $connection->id,
            'resource_key' => 'listings',
            'enabled' => true,
        ]);

        $this->assertDatabaseHas('connection_sync_profiles', [
            'connection_id' => $connection->id,
            'resource_key' => 'ads',
            'enabled' => false,
        ]);
    }

    #[Test]
    public function update_sync_profiles_persists_enabled_and_include(): void
    {
        [, , $connection] = $this->actingMemberWithMeliConnection();

        $this->put(route('connections.sync-profiles.update', $connection), [
            'profiles' => [
                [
                    'resource_key' => 'listings',
                    'enabled' => true,
                    'config' => [
                        'include' => [
                            'raw_snapshot' => true,
                            'price' => true,
                            'stock' => true,
                            'pictures' => true,
                            'description' => true,
                            'attributes' => false,
                        ],
                    ],
                ],
                [
                    'resource_key' => 'orders',
                    'enabled' => false,
                    'config' => ['include' => ['raw_snapshot' => true]],
                ],
            ],
        ])->assertRedirect(route('connections.show', $connection));

        $listings = ConnectionSyncProfile::query()
            ->where('connection_id', $connection->id)
            ->where('resource_key', 'listings')
            ->firstOrFail();

        $this->assertTrue($listings->enabled);
        $this->assertTrue($listings->includes('pictures'));
        $this->assertTrue($listings->includes('description'));

        $orders = ConnectionSyncProfile::query()
            ->where('connection_id', $connection->id)
            ->where('resource_key', 'orders')
            ->firstOrFail();

        $this->assertFalse($orders->enabled);
    }

    #[Test]
    public function bootstrap_skips_when_listings_disabled(): void
    {
        [, , $connection] = $this->actingMemberWithMeliConnection();

        ConnectionSyncProfile::query()->create([
            'workspace_id' => $connection->workspace_id,
            'connection_id' => $connection->id,
            'resource_key' => 'listings',
            'enabled' => false,
            'config' => ['include' => []],
        ]);

        Http::preventStrayRequests();

        (new BootstrapMercadoLibreListingsJob(
            (int) $connection->workspace_id,
            (int) $connection->id,
        ))->handle();

        $this->assertSame(0, ChannelListing::query()
            ->where('connection_id', $connection->id)
            ->count());
    }

    #[Test]
    public function bootstrap_projects_price_stock_pictures_and_raw_when_enabled(): void
    {
        [, , $connection] = $this->actingMemberWithMeliConnection();

        ConnectionSyncProfile::query()->create([
            'workspace_id' => $connection->workspace_id,
            'connection_id' => $connection->id,
            'resource_key' => 'listings',
            'enabled' => true,
            'config' => [
                'include' => [
                    'raw_snapshot' => true,
                    'price' => true,
                    'stock' => true,
                    'pictures' => true,
                    'description' => false,
                    'attributes' => false,
                ],
                'statuses' => ['active'],
            ],
        ]);

        Http::preventStrayRequests();
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();

            if (str_contains($url, '/items/search')) {
                return Http::response([
                    'results' => ['MLM123'],
                    'paging' => ['total' => 1, 'offset' => 0, 'limit' => 50],
                ], 200);
            }

            if (preg_match('#/items(\?|$)#', $url) === 1) {
                return Http::response([
                    [
                        'code' => 200,
                        'body' => [
                            'id' => 'MLM123',
                            'title' => 'Demo listing',
                            'status' => 'active',
                            'permalink' => 'https://articulo.mercadolibre.com.mx/MLM-123',
                            'price' => 199.5,
                            'currency_id' => 'MXN',
                            'available_quantity' => 7,
                            'seller_custom_field' => 'DEMO-001',
                            'pictures' => [
                                ['id' => 'P1', 'secure_url' => 'https://http2.mlstatic.com/p1.jpg', 'size' => '500x500'],
                            ],
                            'variations' => [],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'unmocked', 'url' => $url], 500);
        });

        (new BootstrapMercadoLibreListingsJob(
            (int) $connection->workspace_id,
            (int) $connection->id,
        ))->handle();

        $listing = ChannelListing::query()
            ->where('connection_id', $connection->id)
            ->where('external_item_id', 'MLM123')
            ->firstOrFail();
        $this->assertNotNull($listing->raw_snapshot_id);
        $this->assertNotNull($listing->pictures);
        $this->assertSame('https://http2.mlstatic.com/p1.jpg', $listing->pictures[0]['url']);

        $variant = ChannelListingVariant::query()
            ->where('channel_listing_id', $listing->id)
            ->firstOrFail();

        $this->assertSame('199.500000', (string) $variant->price_amount);
        $this->assertSame('MXN', $variant->currency_code);
        $this->assertSame(7, $variant->available_quantity);

        $this->assertSame(1, RawResourceSnapshot::query()
            ->where('connection_id', $connection->id)
            ->where('resource_type', 'item')
            ->count());
        $this->assertDatabaseHas('sync_cursors', [
            'connection_id' => $connection->id,
            'resource_type' => 'listings',
        ]);
    }

    #[Test]
    public function sync_now_dispatches_listings_job_when_enabled(): void
    {
        Queue::fake();
        [, , $connection] = $this->actingMemberWithMeliConnection();

        ConnectionSyncProfile::query()->create([
            'workspace_id' => $connection->workspace_id,
            'connection_id' => $connection->id,
            'resource_key' => 'listings',
            'enabled' => true,
            'config' => ['include' => ['raw_snapshot' => true]],
        ]);

        $this->post(route('connections.sync-now', $connection), [
            'resource_key' => 'listings',
        ])->assertRedirect(route('connections.show', $connection));

        Queue::assertPushed(BootstrapMercadoLibreListingsJob::class);
    }

    #[Test]
    public function sync_now_dispatches_orders_job_when_enabled(): void
    {
        Queue::fake();
        [, , $connection] = $this->actingMemberWithMeliConnection();

        ConnectionSyncProfile::query()->create([
            'workspace_id' => $connection->workspace_id,
            'connection_id' => $connection->id,
            'resource_key' => 'orders',
            'enabled' => true,
            'config' => ['include' => ['raw_snapshot' => true], 'lookback_days' => 90],
        ]);

        $this->post(route('connections.sync-now', $connection), [
            'resource_key' => 'orders',
        ])->assertRedirect(route('connections.show', $connection));

        Queue::assertPushed(BootstrapMercadoLibreOrdersJob::class);
    }
}
