<?php

namespace Tests\Feature;

use App\Domain\Catalog\Actions\UpsertChannelListing;
use App\Models\ChannelListing;
use App\Models\ChannelListingVariant;
use App\Models\Connection;
use App\Models\CostLayer;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Models\Warehouse;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdoptListingAndMarketplaceCostAlertTest extends TestCase
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
    private function actingMemberWithConnection(): array
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
            'external_user_id' => '999',
            'status' => 'active',
            'token_generation' => 1,
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$user, $workspace, $connection];
    }

    #[Test]
    public function upsert_adopts_unmatched_listing_with_synthetic_sku(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithConnection();

        $listing = app(UpsertChannelListing::class)->execute($connection, [
            'id' => 'MLM999',
            'title' => 'Adopted from ML',
            'status' => 'active',
            'permalink' => 'https://example.test/MLM999',
            'variations' => [],
        ]);

        $clv = ChannelListingVariant::query()
            ->where('channel_listing_id', $listing->id)
            ->first();

        $this->assertNotNull($clv);
        $this->assertNotNull($clv->variant_id);

        $variant = Variant::query()->findOrFail($clv->variant_id);
        $this->assertSame('ML-MLM999', $variant->sku);
        $this->assertSame($workspace->id, $variant->workspace_id);

        $product = Product::query()->findOrFail($variant->product_id);
        $this->assertSame('Adopted from ML', $product->name);
        $this->assertSame($product->id, $listing->fresh()->product_id);
    }

    #[Test]
    public function upsert_multi_variation_creates_one_product_and_n_variants(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithConnection();

        $listing = app(UpsertChannelListing::class)->execute($connection, [
            'id' => 'MLM777',
            'title' => 'Multi var item',
            'status' => 'active',
            'variations' => [
                ['id' => '11', 'seller_sku' => 'SKU-A', 'price' => 100],
                ['id' => '22', 'seller_sku' => 'SKU-B', 'price' => 120],
            ],
        ]);

        $clvs = ChannelListingVariant::query()
            ->where('channel_listing_id', $listing->id)
            ->orderBy('external_variation_id')
            ->get();

        $this->assertCount(2, $clvs);
        $this->assertNotNull($clvs[0]->variant_id);
        $this->assertNotNull($clvs[1]->variant_id);

        $variantIds = $clvs->pluck('variant_id')->all();
        $variants = Variant::query()->whereIn('id', $variantIds)->get();
        $this->assertCount(2, $variants);
        $this->assertSame(1, $variants->pluck('product_id')->unique()->count());
        $this->assertEqualsCanonicalizing(['SKU-A', 'SKU-B'], $variants->pluck('sku')->all());
        $this->assertSame($workspace->id, $variants->first()->workspace_id);
    }

    #[Test]
    public function re_upsert_is_idempotent_and_does_not_duplicate_products(): void
    {
        [, , $connection] = $this->actingMemberWithConnection();
        $upsert = app(UpsertChannelListing::class);

        $payload = [
            'id' => 'MLM555',
            'title' => 'Idempotent',
            'status' => 'active',
            'variations' => [],
        ];

        $first = $upsert->execute($connection, $payload);
        $second = $upsert->execute($connection, $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Product::query()->where('name', 'Idempotent')->count());
        $this->assertSame(1, Variant::query()->where('sku', 'ML-MLM555')->count());
    }

    #[Test]
    public function marketplace_without_cost_filter_and_dashboard_count(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithConnection();

        $missingListing = app(UpsertChannelListing::class)->execute($connection, [
            'id' => 'MLM-MISSING',
            'title' => 'Needs cost',
            'status' => 'active',
            'variations' => [],
        ]);

        $okListing = app(UpsertChannelListing::class)->execute($connection, [
            'id' => 'MLM-OK',
            'title' => 'Has cost',
            'status' => 'active',
            'variations' => [],
            'seller_sku' => 'OK-SKU',
        ]);

        $okVariantId = ChannelListingVariant::query()
            ->where('channel_listing_id', $okListing->id)
            ->value('variant_id');

        $warehouse = Warehouse::factory()->create(['workspace_id' => $workspace->id]);
        CostLayer::factory()->create([
            'workspace_id' => $workspace->id,
            'variant_id' => $okVariantId,
            'warehouse_id' => $warehouse->id,
        ]);

        $this->assertSame(1, ChannelListing::countWithoutCostForWorkspace($workspace->id));

        $this->get(route('publications.index', ['without_cost' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Publications/Index')
                ->where('filters.without_cost', true)
                ->where('listings_without_cost_count', 1)
                ->has('listings.data', 1)
                ->where('listings.data.0.id', $missingListing->id)
                ->where('listings.data.0.missing_cost', true));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('kpis.listings_without_cost', 1));

        $this->get(route('finance.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/Dashboard')
                ->where('listings_without_cost', 1));
    }

    #[Test]
    public function adopt_unmatched_listings_command_backfills(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithConnection();

        $listing = ChannelListing::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'external_item_id' => 'MLM-BACKFILL',
            'title' => 'Backfill me',
            'status' => 'active',
        ]);

        ChannelListingVariant::query()->create([
            'workspace_id' => $workspace->id,
            'channel_listing_id' => $listing->id,
            'external_variation_id' => '0',
            'sku_external' => null,
            'status' => 'active',
            'variant_id' => null,
        ]);

        Artisan::call('catalog:adopt-unmatched-listings', [
            '--workspace' => $workspace->id,
        ]);

        $listing->refresh();
        $clv = $listing->variants()->first();
        $this->assertNotNull($clv?->variant_id);
        $this->assertSame('ML-MLM-BACKFILL', Variant::query()->findOrFail($clv->variant_id)->sku);
    }
}
