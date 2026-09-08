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

class CreateProductTest extends TestCase
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

    #[Test]
    public function create_page_renders_for_workspace_member(): void
    {
        $this->actingMember();

        $this->get(route('products.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Products/Create'));
    }

    #[Test]
    public function can_create_product_with_variants(): void
    {
        [, $workspace] = $this->actingMember();

        $response = $this->post(route('products.store'), [
            'name' => 'Widget Pro',
            'description' => 'A demo widget',
            'status' => 'active',
            'variants' => [
                ['sku' => 'WDG-001', 'name' => 'Default', 'gtin' => null],
                ['sku' => 'WDG-002', 'name' => 'Large', 'gtin' => '1234567890123'],
            ],
        ]);

        $response->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'workspace_id' => $workspace->id,
            'name' => 'Widget Pro',
            'description' => 'A demo widget',
            'status' => 'active',
        ]);

        $product = Product::query()
            ->where('workspace_id', $workspace->id)
            ->where('name', 'Widget Pro')
            ->firstOrFail();

        $this->assertDatabaseHas('variants', [
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'sku' => 'WDG-001',
            'name' => 'Default',
        ]);
        $this->assertDatabaseHas('variants', [
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'sku' => 'WDG-002',
            'gtin' => '1234567890123',
        ]);
        $this->assertSame(2, $product->variants()->count());
    }

    #[Test]
    public function rejects_duplicate_sku_in_workspace(): void
    {
        [, $workspace] = $this->actingMember();

        $product = Product::factory()->create(['workspace_id' => $workspace->id]);
        Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'sku' => 'TAKEN-SKU',
        ]);

        $response = $this->from(route('products.create'))->post(route('products.store'), [
            'name' => 'Another product',
            'variants' => [
                ['sku' => 'TAKEN-SKU', 'name' => null, 'gtin' => null],
            ],
        ]);

        $response->assertSessionHasErrors('variants.0.sku');
        $this->assertDatabaseMissing('products', [
            'workspace_id' => $workspace->id,
            'name' => 'Another product',
        ]);
    }

    #[Test]
    public function auto_matches_unmatched_listings_by_sku(): void
    {
        [, $workspace] = $this->actingMember();

        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
        ]);

        $listing = ChannelListing::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'external_item_id' => 'MLM123',
            'title' => 'Existing ML item',
            'status' => 'active',
        ]);

        $listingVariant = ChannelListingVariant::query()->create([
            'workspace_id' => $workspace->id,
            'channel_listing_id' => $listing->id,
            'variant_id' => null,
            'external_variation_id' => 'VAR1',
            'sku_external' => 'MATCH-ME',
            'status' => 'active',
        ]);

        $this->post(route('products.store'), [
            'name' => 'Matched product',
            'variants' => [
                ['sku' => 'MATCH-ME', 'name' => 'Default', 'gtin' => null],
            ],
        ])->assertRedirect(route('products.index'));

        $variant = Variant::query()
            ->where('workspace_id', $workspace->id)
            ->where('sku', 'MATCH-ME')
            ->firstOrFail();

        $this->assertSame(
            $variant->id,
            $listingVariant->fresh()->variant_id,
        );
    }
}
