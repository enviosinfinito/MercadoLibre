<?php

namespace App\Domain\Catalog\Actions;

use App\Models\ChannelListingVariant;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

final class ArchiveProduct
{
    public function execute(Product $product): void
    {
        DB::transaction(function () use ($product) {
            $variantIds = $product->variants()->pluck('id');

            if ($variantIds->isNotEmpty()) {
                ChannelListingVariant::query()
                    ->whereIn('variant_id', $variantIds)
                    ->update(['variant_id' => null]);

                $product->variants()->delete();
            }

            $product->delete();
        });
    }
}
