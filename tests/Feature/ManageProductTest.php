<?php

namespace Tests\Feature;

use App\Models\ChannelListing;
use App\Models\ChannelListingVariant;
use App\Models\Connection;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManageProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function actingMember(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();

        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$user, $workspace];
    }

    /**
     * @return array{0: Product, 1: Variant}
     */
    private function productWithVariant(Workspace $workspace, string $sku = 'SKU-001'): array
    {
        $product = Product::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Original name',
            'description' => 'Original description',
            'status' => 'active',
        ]);

        $variant = Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'sku' => $sku,
            'name' => 'Default',
            'gtin' => null,
        ]);

        return [$product, $variant];
    }

    #[Test]
    public function edit_page_renders_for_workspace_member(): void
    {
        [, $workspace] = $this->actingMember();
        [$product] = $this->productWithVariant($workspace);

        $this->get(route('products.edit', $product))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Products/Edit')
                ->has('product.variants', 1));
    }

    #[Test]
    public function can_update_product_and_variants(): void
    {
        [, $workspace] = $this->actingMember();
        [$product, $variant] = $this->productWithVariant($workspace, 'OLD-SKU');

        $response = $this->put(route('products.update', $product), [
            'name' => 'Updated name',
            'description' => 'Updated description',
            'status' => 'inactive',
            'variants' => [
                [
                    'id' => $variant->id,
                    'sku' => 'NEW-SKU',
                    'name' => 'Renamed',
                    'gtin' => '1234567890123',
                ],
                [
                    'sku' => 'EXTRA-SKU',
                    'name' => 'Extra',
                    'gtin' => null,
                ],
            ],
        ]);

        $response->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Updated name',
            'description' => 'Updated description',
            'status' => 'inactive',
        ]);

        $this->assertDatabaseHas('variants', [
            'id' => $variant->id,
            'sku' => 'NEW-SKU',
            'name' => 'Renamed',
            'gtin' => '1234567890123',
        ]);

        $this->assertDatabaseHas('variants', [
            'product_id' => $product->id,
            'sku' => 'EXTRA-SKU',
            'name' => 'Extra',
        ]);

        $this->assertSame(2, $product->fresh()->variants()->count());
    }

    #[Test]
    public function update_rejects_duplicate_sku_in_workspace(): void
    {
        [, $workspace] = $this->actingMember();
        [$product, $variant] = $this->productWithVariant($workspace, 'KEEP-SKU');

        $other = Product::factory()->create(['workspace_id' => $workspace->id]);
        Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $other->id,
            'sku' => 'TAKEN-SKU',
        ]);

        $response = $this->from(route('products.edit', $product))->put(route('products.update', $product), [
            'name' => 'Updated name',
            'variants' => [
                [
                    'id' => $variant->id,
                    'sku' => 'TAKEN-SKU',
                    'name' => null,
                    'gtin' => null,
                ],
            ],
        ]);

        $response->assertSessionHasErrors('variants.0.sku');
        $this->assertSame('KEEP-SKU', $variant->fresh()->sku);
    }

    #[Test]
    public function update_allows_keeping_same_sku(): void
    {
        [, $workspace] = $this->actingMember();
        [$product, $variant] = $this->productWithVariant($workspace, 'SAME-SKU');

        $this->put(route('products.update', $product), [
            'name' => 'Still same SKU',
            'variants' => [
                [
                    'id' => $variant->id,
                    'sku' => 'SAME-SKU',
                    'name' => 'Default',
                    'gtin' => null,
                ],
            ],
        ])->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Still same SKU',
        ]);
    }

    #[Test]
    public function archive_soft_deletes_and_unmatches_listings(): void
    {
        [, $workspace] = $this->actingMember();
        [$product, $variant] = $this->productWithVariant($workspace, 'ARCH-SKU');

        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
        ]);

        $listing = ChannelListing::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'external_item_id' => 'MLM-ARCH',
            'title' => 'Matched item',
            'status' => 'active',
        ]);

        $listingVariant = ChannelListingVariant::query()->create([
            'workspace_id' => $workspace->id,
            'channel_listing_id' => $listing->id,
            'variant_id' => $variant->id,
            'external_variation_id' => 'VAR-ARCH',
            'sku_external' => 'ARCH-SKU',
            'status' => 'active',
        ]);

        $this->delete(route('products.destroy', $product))
            ->assertRedirect(route('products.index'));

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertSoftDeleted('variants', ['id' => $variant->id]);
        $this->assertNull($listingVariant->fresh()->variant_id);
    }

    #[Test]
    public function restore_restores_product_and_rematches_by_sku(): void
    {
        [, $workspace] = $this->actingMember();
        [$product, $variant] = $this->productWithVariant($workspace, 'RESTORE-SKU');

        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
        ]);

        $listing = ChannelListing::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'external_item_id' => 'MLM-RESTORE',
            'title' => 'To rematch',
            'status' => 'active',
        ]);

        $listingVariant = ChannelListingVariant::query()->create([
            'workspace_id' => $workspace->id,
            'channel_listing_id' => $listing->id,
            'variant_id' => $variant->id,
            'external_variation_id' => 'VAR-RESTORE',
            'sku_external' => 'RESTORE-SKU',
            'status' => 'active',
        ]);

        $this->delete(route('products.destroy', $product))->assertRedirect();
        $this->assertNull($listingVariant->fresh()->variant_id);

        $this->post(route('products.restore', $product->id))
            ->assertRedirect(route('products.index'));

        $this->assertNull($product->fresh()->deleted_at);
        $this->assertNull($variant->fresh()->deleted_at);
        $this->assertSame($variant->id, $listingVariant->fresh()->variant_id);
    }

    #[Test]
    public function edit_page_shows_matched_listings(): void
    {
        [, $workspace] = $this->actingMember();
        [$product, $variant] = $this->productWithVariant($workspace, 'LINKED-SKU');

        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
        ]);

        $listing = ChannelListing::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'external_item_id' => 'MLM-LINKED',
            'title' => 'Linked ML publication',
            'status' => 'active',
            'permalink' => 'https://articulo.mercadolibre.com.mx/MLM-LINKED',
        ]);

        ChannelListingVariant::query()->create([
            'workspace_id' => $workspace->id,
            'channel_listing_id' => $listing->id,
            'variant_id' => $variant->id,
            'external_variation_id' => 'VAR-LINKED',
            'sku_external' => 'LINKED-SKU',
            'status' => 'active',
        ]);

        $this->get(route('products.edit', $product))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Products/Edit')
                ->has('matchedListings', 1)
                ->where('matchedListings.0.external_item_id', 'MLM-LINKED')
                ->where('matchedListings.0.title', 'Linked ML publication')
                ->where('matchedListings.0.variant_sku', 'LINKED-SKU'));

        $this->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products.data.0.matched_listings_count', 1)
                ->has('products.data.0.matched_listings', 1)
                ->where('products.data.0.matched_listings.0.external_item_id', 'MLM-LINKED')
                ->where('products.data.0.matched_listings.0.title', 'Linked ML publication'));
    }

    #[Test]
    public function index_archived_filter_shows_only_trashed_products(): void
    {
        [, $workspace] = $this->actingMember();
        [$active] = $this->productWithVariant($workspace, 'ACTIVE-SKU');
        [$archived] = $this->productWithVariant($workspace, 'ARCHIVED-SKU');
        $archived->delete();

        $this->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Products/Index')
                ->where('archived', false)
                ->has('products.data', 1)
                ->where('products.data.0.id', $active->id));

        $this->get(route('products.index', ['archived' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Products/Index')
                ->where('archived', true)
                ->has('products.data', 1)
                ->where('products.data.0.id', $archived->id));
    }
}
