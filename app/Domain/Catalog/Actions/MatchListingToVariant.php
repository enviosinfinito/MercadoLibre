<?php

namespace App\Domain\Catalog\Actions;

use App\Models\ChannelListingVariant;
use App\Models\Variant;

final class MatchListingToVariant
{
    public function execute(
        ChannelListingVariant $listingVariant,
        ?string $sku = null,
        ?int $variantId = null,
    ): ChannelListingVariant {
        if ($variantId !== null) {
            $variant = Variant::query()
                ->where('workspace_id', $listingVariant->workspace_id)
                ->whereKey($variantId)
                ->firstOrFail();

            $listingVariant->variant_id = $variant->id;
            $listingVariant->save();

            return $listingVariant->fresh();
        }

        $sku ??= $listingVariant->sku_external;

        if ($sku === null || $sku === '') {
            return $listingVariant;
        }

        $variant = Variant::query()
            ->where('workspace_id', $listingVariant->workspace_id)
            ->where('sku', $sku)
            ->first();

        if ($variant) {
            $listingVariant->variant_id = $variant->id;
            $listingVariant->save();
        }

        return $listingVariant->fresh();
    }
}
