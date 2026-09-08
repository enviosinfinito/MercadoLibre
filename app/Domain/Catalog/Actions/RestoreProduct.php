<?php

namespace App\Domain\Catalog\Actions;

use App\Models\ChannelListingVariant;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;

final class RestoreProduct
{
    public function __construct(
        private readonly MatchListingToVariant $matchListingToVariant,
    ) {}

    public function execute(Product $product): Product
    {
        return DB::transaction(function () use ($product) {
            $product->restore();

            Variant::onlyTrashed()
                ->where('product_id', $product->id)
                ->get()
                ->each(fn (Variant $variant) => $variant->restore());

            $product->load('variants');

            foreach ($product->variants as $variant) {
                $unmatched = ChannelListingVariant::query()
                    ->where('workspace_id', $product->workspace_id)
                    ->whereNull('variant_id')
                    ->where('sku_external', $variant->sku)
                    ->get();

                foreach ($unmatched as $listingVariant) {
                    $this->matchListingToVariant->execute(
                        $listingVariant,
                        variantId: $variant->id,
                    );
                }
            }

            return $product->fresh()->load('variants');
        });
    }
}
