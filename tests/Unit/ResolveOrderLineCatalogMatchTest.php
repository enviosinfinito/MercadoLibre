<?php

namespace Tests\Unit;

use App\Domain\Sales\Actions\ResolveOrderLineCatalogMatch;
use App\Models\ChannelListing;
use App\Models\ChannelListingVariant;
use App\Models\Connection;
use App\Models\Product;
use App\Models\Variant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResolveOrderLineCatalogMatchTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function matches_by_sku_and_attaches_listing_variant(): void
    {
        [$workspace, $connection, $variant, $clv] = $this->seedCatalog();

        $match = app(ResolveOrderLineCatalogMatch::class)->execute(
            $workspace->id,
            $connection->id,
            'FULL-001',
            'MLM123',
            null,
        );

        $this->assertSame($variant->id, $match['variant_id']);
        $this->assertSame($clv->id, $match['channel_listing_variant_id']);
        $this->assertSame('matched', $match['match_status']);
    }

    #[Test]
    public function matches_listing_when_seller_sku_differs(): void
    {
        [$workspace, $connection, $variant, $clv] = $this->seedCatalog();

        $match = app(ResolveOrderLineCatalogMatch::class)->execute(
            $workspace->id,
            $connection->id,
            'OTHER-SKU',
            'MLM123',
            null,
        );

        $this->assertSame($variant->id, $match['variant_id']);
        $this->assertSame($clv->id, $match['channel_listing_variant_id']);
        $this->assertSame('matched', $match['match_status']);
    }

    #[Test]
    public function matches_variation_id_among_multiple_clvs(): void
    {
        [$workspace, $connection, $variant] = $this->seedCatalog();
        $listing = ChannelListing::query()
            ->where('workspace_id', $workspace->id)
            ->where('external_item_id', 'MLM123')
            ->firstOrFail();

        $otherVariant = Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $variant->product_id,
            'sku' => 'FULL-002',
            'status' => 'active',
        ]);

        $clvB = ChannelListingVariant::query()->create([
            'workspace_id' => $workspace->id,
            'channel_listing_id' => $listing->id,
            'variant_id' => $otherVariant->id,
            'external_variation_id' => '777',
            'sku_external' => 'FULL-002',
            'status' => 'active',
        ]);

        $match = app(ResolveOrderLineCatalogMatch::class)->execute(
            $workspace->id,
            $connection->id,
            null,
            'MLM123',
            '777',
        );

        $this->assertSame($otherVariant->id, $match['variant_id']);
        $this->assertSame($clvB->id, $match['channel_listing_variant_id']);
        $this->assertSame('matched', $match['match_status']);
    }

    #[Test]
    public function does_not_guess_when_multiple_clvs_lack_variation_id(): void
    {
        [$workspace, $connection, $variant] = $this->seedCatalog();
        $listing = ChannelListing::query()
            ->where('workspace_id', $workspace->id)
            ->where('external_item_id', 'MLM123')
            ->firstOrFail();

        ChannelListingVariant::query()->create([
            'workspace_id' => $workspace->id,
            'channel_listing_id' => $listing->id,
            'variant_id' => $variant->id,
            'sku_external' => 'FULL-ALT',
            'status' => 'active',
        ]);

        $match = app(ResolveOrderLineCatalogMatch::class)->execute(
            $workspace->id,
            $connection->id,
            'UNKNOWN',
            'MLM123',
            null,
        );

        $this->assertNull($match['variant_id']);
        $this->assertNull($match['channel_listing_variant_id']);
        $this->assertSame('unmatched', $match['match_status']);
    }

    #[Test]
    public function falls_back_to_sku_external(): void
    {
        [$workspace, $connection, $variant] = $this->seedCatalog();
        $listing = ChannelListing::query()
            ->where('workspace_id', $workspace->id)
            ->where('external_item_id', 'MLM123')
            ->firstOrFail();

        $clv = ChannelListingVariant::query()->create([
            'workspace_id' => $workspace->id,
            'channel_listing_id' => $listing->id,
            'variant_id' => $variant->id,
            'sku_external' => 'EXT-SKU-1',
            'status' => 'active',
        ]);

        $match = app(ResolveOrderLineCatalogMatch::class)->execute(
            $workspace->id,
            $connection->id,
            'EXT-SKU-1',
            'MLM-MISSING',
            null,
        );

        $this->assertSame($variant->id, $match['variant_id']);
        $this->assertSame($clv->id, $match['channel_listing_variant_id']);
        $this->assertSame('matched', $match['match_status']);
    }

    /**
     * @return array{0: Workspace, 1: Connection, 2: Variant, 3: ChannelListingVariant}
     */
    private function seedCatalog(): array
    {
        $workspace = Workspace::factory()->create();
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
        ]);

        $product = Product::factory()->create(['workspace_id' => $workspace->id]);
        $variant = Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'sku' => 'FULL-001',
            'status' => 'active',
        ]);

        $listing = ChannelListing::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'external_item_id' => 'MLM123',
            'title' => 'Full listing',
            'status' => 'active',
            'logistic_type' => 'fulfillment',
        ]);

        $clv = ChannelListingVariant::query()->create([
            'workspace_id' => $workspace->id,
            'channel_listing_id' => $listing->id,
            'variant_id' => $variant->id,
            'sku_external' => 'FULL-001',
            'status' => 'active',
        ]);

        return [$workspace, $connection, $variant, $clv];
    }
}
