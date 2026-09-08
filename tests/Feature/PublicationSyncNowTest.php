<?php

namespace Tests\Feature;

use App\Models\ChannelListing;
use App\Models\ChannelListingVariant;
use App\Models\Connection;
use App\Models\ConnectionSyncProfile;
use App\Models\EncryptedCredential;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicationSyncNowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);

        config([
            'connectors.mercadolibre.api_base_url' => 'https://api.mercadolibre.com',
            'connectors.mercadolibre.client_id' => 'test-client',
            'connectors.mercadolibre.client_secret' => 'test-secret',
        ]);
    }

    /**
     * @return array{0: Workspace, 1: Connection, 2: ChannelListing}
     */
    private function seedListingWithoutPictureIds(): array
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

        ConnectionSyncProfile::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'resource_key' => 'listings',
            'enabled' => true,
            'config' => [
                'include' => [
                    'raw_snapshot' => true,
                    'price' => true,
                    'stock' => true,
                    'pictures' => false,
                    'description' => false,
                    'attributes' => false,
                ],
                'statuses' => ['active'],
            ],
        ]);

        $listing = ChannelListing::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'external_item_id' => 'MLM100',
            'title' => 'Producto con variantes',
            'status' => 'active',
            'pictures' => [
                ['id' => 'pic-a', 'url' => 'https://http2.mlstatic.com/a.jpg', 'secure_url' => 'https://http2.mlstatic.com/a.jpg'],
                ['id' => 'pic-b', 'url' => 'https://http2.mlstatic.com/b.jpg', 'secure_url' => 'https://http2.mlstatic.com/b.jpg'],
            ],
        ]);

        ChannelListingVariant::query()->create([
            'workspace_id' => $workspace->id,
            'channel_listing_id' => $listing->id,
            'external_variation_id' => '111',
            'sku_external' => 'SKU-A',
            'status' => 'active',
            'picture_ids' => null,
            'price_amount' => 100,
            'currency_code' => 'MXN',
            'available_quantity' => 1,
        ]);

        ChannelListingVariant::query()->create([
            'workspace_id' => $workspace->id,
            'channel_listing_id' => $listing->id,
            'external_variation_id' => '222',
            'sku_external' => 'SKU-B',
            'status' => 'active',
            'picture_ids' => null,
            'price_amount' => 120,
            'currency_code' => 'MXN',
            'available_quantity' => 2,
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$workspace, $connection, $listing];
    }

    #[Test]
    public function sync_now_forces_pictures_and_assigns_picture_ids_per_variant(): void
    {
        [, , $listing] = $this->seedListingWithoutPictureIds();

        Http::preventStrayRequests();
        Http::fake([
            'https://api.mercadolibre.com/items/MLM100' => Http::response([
                'id' => 'MLM100',
                'title' => 'Producto con variantes',
                'status' => 'active',
                'price' => 100,
                'currency_id' => 'MXN',
                'available_quantity' => 3,
                'pictures' => [
                    ['id' => 'pic-a', 'url' => 'http://http2.mlstatic.com/a.jpg', 'secure_url' => 'https://http2.mlstatic.com/a.jpg'],
                    ['id' => 'pic-b', 'url' => 'http://http2.mlstatic.com/b.jpg', 'secure_url' => 'https://http2.mlstatic.com/b.jpg'],
                ],
                'variations' => [
                    [
                        'id' => 111,
                        'price' => 100,
                        'available_quantity' => 1,
                        'seller_custom_field' => 'SKU-A',
                        'picture_ids' => ['pic-a'],
                        'attribute_combinations' => [
                            ['id' => 'COLOR', 'name' => 'Color', 'value_name' => 'Negro'],
                        ],
                    ],
                    [
                        'id' => 222,
                        'price' => 120,
                        'available_quantity' => 2,
                        'seller_custom_field' => 'SKU-B',
                        'picture_ids' => ['pic-b'],
                        'attribute_combinations' => [
                            ['id' => 'COLOR', 'name' => 'Color', 'value_name' => 'Blanco'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->postJson(route('publications.sync-now', $listing))
            ->assertOk()
            ->assertJsonPath('listing.external_item_id', 'MLM100')
            ->assertJsonPath('listing.variants.0.picture_ids.0', 'pic-a')
            ->assertJsonPath('listing.variants.1.picture_ids.0', 'pic-b');

        $this->assertSame(['pic-a'], $listing->variants()->where('external_variation_id', '111')->value('picture_ids'));
        $this->assertSame(['pic-b'], $listing->variants()->where('external_variation_id', '222')->value('picture_ids'));
    }

    #[Test]
    public function product_edit_needs_resync_only_when_multiple_canonical_variants(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);

        $product = Product::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Solo una variante',
        ]);
        $variant = Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'sku' => 'ONLY-1',
        ]);

        $listing = ChannelListing::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'external_item_id' => 'MLM-SINGLE',
            'title' => 'Solo una variante',
            'status' => 'active',
            'pictures' => [
                ['id' => 'pic-1', 'url' => 'https://http2.mlstatic.com/1.jpg'],
            ],
        ]);

        ChannelListingVariant::query()->create([
            'workspace_id' => $workspace->id,
            'channel_listing_id' => $listing->id,
            'variant_id' => $variant->id,
            'external_variation_id' => '0',
            'sku_external' => 'ONLY-1',
            'status' => 'active',
            'picture_ids' => null,
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        $this->getJson(route('products.edit', $product))
            ->assertOk()
            ->assertJsonPath('picture_gallery.needs_resync', false);
    }
}
