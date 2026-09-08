<?php

namespace App\Domain\Catalog\Actions;

use App\Models\ChannelListing;
use App\Models\ChannelListingVariant;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;

final class AdoptListingAsCanonicalProduct
{
    public function __construct(
        private readonly MatchListingToVariant $matchListingToVariant,
    ) {}

    public function execute(ChannelListing $listing): ChannelListing
    {
        if (! in_array((string) $listing->status, ['active', 'paused'], true)) {
            return $listing->fresh(['variants']) ?? $listing;
        }

        return DB::transaction(function () use ($listing) {
            /** @var ChannelListing $listing */
            $listing = ChannelListing::query()
                ->whereKey($listing->id)
                ->lockForUpdate()
                ->with(['variants.variant'])
                ->firstOrFail();

            $unmatched = $listing->variants
                ->filter(fn (ChannelListingVariant $clv) => $clv->variant_id === null)
                ->values();

            if ($unmatched->isEmpty()) {
                return $listing->fresh(['variants']);
            }

            $product = $this->resolveProduct($listing);

            if ((int) $listing->product_id !== (int) $product->id) {
                $listing->product_id = $product->id;
                $listing->save();
            }

            foreach ($unmatched as $clv) {
                $sku = $this->resolveSku($listing, $clv);

                $existing = Variant::query()
                    ->where('workspace_id', $listing->workspace_id)
                    ->where('sku', $sku)
                    ->first();

                if ($existing !== null) {
                    $this->matchListingToVariant->execute($clv, variantId: $existing->id);

                    continue;
                }

                $variant = $product->variants()->create([
                    'workspace_id' => $listing->workspace_id,
                    'sku' => $sku,
                    'name' => $this->variantName($clv),
                    'gtin' => null,
                    'status' => 'active',
                ]);

                $this->matchListingToVariant->execute($clv, variantId: $variant->id);
            }

            return $listing->fresh(['variants']);
        });
    }

    private function resolveProduct(ChannelListing $listing): Product
    {
        foreach ($listing->variants as $clv) {
            if ($clv->variant_id !== null && $clv->variant !== null) {
                return $clv->variant->product;
            }
        }

        if ($listing->product_id !== null) {
            $existing = Product::query()
                ->where('workspace_id', $listing->workspace_id)
                ->whereKey($listing->product_id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return Product::query()->create([
            'workspace_id' => $listing->workspace_id,
            'name' => filled($listing->title)
                ? (string) $listing->title
                : 'Item '.$listing->external_item_id,
            'description' => $listing->description,
            'status' => 'active',
        ]);
    }

    private function resolveSku(ChannelListing $listing, ChannelListingVariant $clv): string
    {
        if (filled($clv->sku_external)) {
            return trim((string) $clv->sku_external);
        }

        $itemId = (string) $listing->external_item_id;
        $variationId = (string) ($clv->external_variation_id ?? '0');

        if ($variationId === '' || $variationId === '0') {
            return 'ML-'.$itemId;
        }

        return 'ML-'.$itemId.'-V-'.$variationId;
    }

    private function variantName(ChannelListingVariant $clv): string
    {
        if (filled($clv->sku_external)) {
            return (string) $clv->sku_external;
        }

        $variationId = (string) ($clv->external_variation_id ?? '0');
        if ($variationId !== '' && $variationId !== '0') {
            return 'Variation '.$variationId;
        }

        return 'Default';
    }
}
