<?php

namespace Tests\Feature;

use App\Domain\Catalog\Support\ListingFreshness;
use App\Domain\Platform\DiagnosticHttpLogging;
use App\Jobs\BootstrapMercadoLibreListingsJob;
use App\Models\ChannelListing;
use App\Models\Connection;
use App\Models\ConnectionSyncProfile;
use App\Models\EncryptedCredential;
use App\Models\SyncHttpLog;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ListingsSyncEfficiencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    /**
     * @return array{0: Workspace, 1: Connection}
     */
    private function meliConnection(): array
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
            'user_id' => '112184176',
        ]);
        $credential->save();

        return [$workspace, $connection];
    }

    private function seedProfile(Connection $connection, array $include): void
    {
        ConnectionSyncProfile::query()->create([
            'workspace_id' => $connection->workspace_id,
            'connection_id' => $connection->id,
            'resource_key' => 'listings',
            'enabled' => true,
            'config' => [
                'include' => $include,
                'statuses' => ['active'],
            ],
        ]);
    }

    #[Test]
    public function multiget_requests_slim_attributes_from_include(): void
    {
        [, $connection] = $this->meliConnection();
        $this->seedProfile($connection, [
            'raw_snapshot' => true,
            'price' => true,
            'stock' => true,
            'pictures' => false,
            'description' => false,
            'attributes' => false,
        ]);

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/items/search')) {
                return Http::response([
                    'results' => ['MLM1'],
                    'paging' => ['total' => 1, 'offset' => 0, 'limit' => 50],
                ]);
            }

            if (str_contains($url, '/items?') || str_contains($url, '/items&')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $this->assertArrayHasKey('attributes', $query);
                $attrs = explode(',', (string) $query['attributes']);
                $this->assertContains('price', $attrs);
                $this->assertContains('available_quantity', $attrs);
                $this->assertNotContains('pictures', $attrs);
                $this->assertContains('last_updated', $attrs);

                return Http::response([[
                    'code' => 200,
                    'body' => [
                        'id' => 'MLM1',
                        'title' => 'Item',
                        'status' => 'active',
                        'price' => 10,
                        'currency_id' => 'MXN',
                        'available_quantity' => 2,
                        'last_updated' => '2026-08-07T10:00:00.000Z',
                        'seller_custom_field' => 'SKU-1',
                        'variations' => [],
                    ],
                ]]);
            }

            return Http::response(['error' => 'unexpected '.$url], 500);
        });

        (new BootstrapMercadoLibreListingsJob(
            (int) $connection->workspace_id,
            (int) $connection->id,
        ))->handle();

        $this->assertDatabaseHas('channel_listings', [
            'external_item_id' => 'MLM1',
            'connection_id' => $connection->id,
        ]);
    }

    #[Test]
    public function description_404_does_not_fail_bootstrap(): void
    {
        [, $connection] = $this->meliConnection();
        $this->seedProfile($connection, [
            'raw_snapshot' => false,
            'price' => true,
            'stock' => false,
            'pictures' => false,
            'description' => true,
            'attributes' => false,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            '*/items/search*' => Http::response([
                'results' => ['MLM404'],
                'paging' => ['total' => 1, 'offset' => 0, 'limit' => 50],
            ]),
            '*/items?*' => Http::response([[
                'code' => 200,
                'body' => [
                    'id' => 'MLM404',
                    'title' => 'No desc',
                    'status' => 'active',
                    'price' => 1,
                    'currency_id' => 'MXN',
                    'last_updated' => '2026-08-07T11:00:00.000Z',
                    'variations' => [],
                ],
            ]]),
            '*/items/MLM404/description' => Http::response(['message' => 'not found'], 404),
        ]);

        (new BootstrapMercadoLibreListingsJob(
            (int) $connection->workspace_id,
            (int) $connection->id,
        ))->handle();

        $listing = ChannelListing::query()
            ->where('external_item_id', 'MLM404')
            ->firstOrFail();

        $this->assertNull($listing->description);
        $this->assertSame('fresh', $connection->fresh()->freshness_status);
    }

    #[Test]
    public function second_sync_skips_description_when_unchanged(): void
    {
        app(DiagnosticHttpLogging::class)->enable(10);

        [, $connection] = $this->meliConnection();
        $this->seedProfile($connection, [
            'raw_snapshot' => true,
            'price' => true,
            'stock' => false,
            'pictures' => false,
            'description' => true,
            'attributes' => false,
        ]);

        $itemBody = [
            'id' => 'MLM99',
            'title' => 'Stable',
            'status' => 'active',
            'price' => 50,
            'currency_id' => 'MXN',
            'last_updated' => '2026-08-07T12:00:00.000Z',
            'seller_custom_field' => 'SKU-99',
            'variations' => [],
        ];

        Http::preventStrayRequests();
        Http::fake([
            '*/items/search*' => Http::response([
                'results' => ['MLM99'],
                'paging' => ['total' => 1, 'offset' => 0, 'limit' => 50],
            ]),
            '*/items?*' => Http::response([['code' => 200, 'body' => $itemBody]]),
            '*/items/MLM99/description' => Http::response(['plain_text' => 'Hola mundo']),
        ]);

        (new BootstrapMercadoLibreListingsJob(
            (int) $connection->workspace_id,
            (int) $connection->id,
        ))->handle();

        $listing = ChannelListing::query()->where('external_item_id', 'MLM99')->firstOrFail();
        $this->assertSame('Hola mundo', $listing->description);
        $this->assertNotNull($listing->external_updated_at);

        $descCallsBefore = SyncHttpLog::query()
            ->where('connection_id', $connection->id)
            ->where('endpoint_group', 'items.description')
            ->count();

        Http::fake([
            '*/items/search*' => Http::response([
                'results' => ['MLM99'],
                'paging' => ['total' => 1, 'offset' => 0, 'limit' => 50],
            ]),
            '*/items?*' => Http::response([['code' => 200, 'body' => $itemBody]]),
            '*/items/MLM99/description' => Http::response(['plain_text' => 'SHOULD NOT BE CALLED'], 200),
        ]);

        (new BootstrapMercadoLibreListingsJob(
            (int) $connection->workspace_id,
            (int) $connection->id,
        ))->handle();

        $descCallsAfter = SyncHttpLog::query()
            ->where('connection_id', $connection->id)
            ->where('endpoint_group', 'items.description')
            ->count();

        $this->assertSame($descCallsBefore, $descCallsAfter);
        $this->assertSame('Hola mundo', $listing->fresh()->description);

        $run = SyncRun::query()
            ->where('connection_id', $connection->id)
            ->orderByDesc('id')
            ->firstOrFail();
        $this->assertGreaterThanOrEqual(1, (int) ($run->stats['descriptions_skipped'] ?? 0));
    }

    #[Test]
    public function listing_freshness_helper_detects_unchanged(): void
    {
        $item = [
            'id' => 'MLM1',
            'title' => 'X',
            'last_updated' => '2026-08-07T12:00:00.000Z',
        ];
        $checksum = ListingFreshness::contentChecksum($item);

        $listing = new ChannelListing([
            'description' => 'kept',
            'content_checksum' => $checksum,
            'external_updated_at' => '2026-08-07T12:00:00.000Z',
        ]);

        $this->assertFalse(ListingFreshness::shouldFetchDescription($listing, $item, $checksum));
        $this->assertTrue(ListingFreshness::shouldFetchDescription(null, $item, $checksum));
    }
}
