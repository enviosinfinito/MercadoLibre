<?php

namespace Tests\Feature;

use App\Jobs\BootstrapMercadoLibreListingsJob;
use App\Models\ChannelListing;
use App\Models\ChannelListingVariant;
use App\Models\Connection;
use App\Models\EncryptedCredential;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BootstrapMercadoLibreListingsTest extends TestCase
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

    private function fakeMeliHttp(): void
    {
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
                            'seller_custom_field' => 'DEMO-001',
                            'variations' => [],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'unmocked', 'url' => $url], 500);
        });
    }

    #[Test]
    public function pull_and_upsert_listing_matches_sku(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();
        $this->fakeMeliHttp();

        $product = Product::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Demo Product',
            'status' => 'active',
        ]);

        $variant = Variant::query()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'sku' => 'DEMO-001',
            'name' => 'Default',
            'status' => 'active',
        ]);

        $connector = app(\App\Integrations\MercadoLibre\Connector\MercadoLibreConnector::class);
        $result = $connector->pull(new \App\Integrations\Contracts\Dto\PullRequest(
            resource: 'listings',
            cursor: [],
            options: [
                'access_token' => 'test-access-token',
                'user_id' => '112184176',
            ],
        ));

        $this->assertCount(1, $result->items);
        $this->assertTrue((bool) ($result->nextCursor['done'] ?? false));

        $listing = app(\App\Domain\Catalog\Actions\UpsertChannelListing::class)
            ->execute($connection, $result->items[0]);

        $this->assertSame('MLM123', $listing->external_item_id);
        $this->assertSame('Demo listing', $listing->title);

        $listingVariant = ChannelListingVariant::query()
            ->where('channel_listing_id', $listing->id)
            ->first();

        $this->assertNotNull($listingVariant);
        $this->assertSame('DEMO-001', $listingVariant->sku_external);
        $this->assertSame($variant->id, $listingVariant->variant_id);
        $this->assertSame($workspace->id, $listing->workspace_id);
    }

    #[Test]
    public function bootstrap_job_persists_listings(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();
        $this->fakeMeliHttp();

        (new BootstrapMercadoLibreListingsJob($workspace->id, $connection->id))->handle();

        $this->assertDatabaseHas('channel_listings', [
            'workspace_id' => $workspace->id,
            'external_item_id' => 'MLM123',
            'title' => 'Demo listing',
        ]);

        $connection->refresh();
        $this->assertSame('fresh', $connection->freshness_status);
        $this->assertNotNull($connection->last_synced_at);
    }

    #[Test]
    public function sync_listings_endpoint_dispatches_job(): void
    {
        Queue::fake();

        [, , $connection] = $this->actingMemberWithMeliConnection();

        $this->post(route('connections.sync-listings', $connection))
            ->assertRedirect(route('connections.index'));

        Queue::assertPushed(BootstrapMercadoLibreListingsJob::class, function ($job) use ($connection) {
            return $job->connectionId === $connection->id
                && $job->workspaceId === $connection->workspace_id;
        });
    }
}
