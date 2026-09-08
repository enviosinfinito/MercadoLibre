<?php

namespace App\Domain\Sales\Actions;

use App\Models\ChannelListing;
use App\Models\ChannelListingVariant;
use App\Models\Variant;
use Illuminate\Support\Collection;

final class ResolveOrderLineCatalogMatch
{
    /**
     * @return array{
     *   variant_id: int|null,
     *   channel_listing_variant_id: int|null,
     *   match_status: string
     * }
     */
    public function execute(
        int $workspaceId,
        int $connectionId,
        ?string $sku,
        ?string $externalItemId,
        ?string $externalVariationId,
    ): array {
        $sku = $sku !== null && trim($sku) !== '' ? trim($sku) : null;
        $externalItemId = $externalItemId !== null && trim($externalItemId) !== ''
            ? trim($externalItemId)
            : null;
        $externalVariationId = $externalVariationId !== null && trim((string) $externalVariationId) !== ''
            ? trim((string) $externalVariationId)
            : null;

        $variantId = null;
        $clvId = null;

        if ($sku !== null) {
            $matchedVariantId = Variant::query()
                ->where('workspace_id', $workspaceId)
                ->where('sku', $sku)
                ->value('id');
            $variantId = $matchedVariantId !== null ? (int) $matchedVariantId : null;
        }

        if ($externalItemId !== null) {
            $listing = ChannelListing::query()
                ->where('workspace_id', $workspaceId)
                ->where('connection_id', $connectionId)
                ->where('external_item_id', $externalItemId)
                ->with(['variants' => fn ($q) => $q->orderBy('id')])
                ->first();

            if ($listing !== null) {
                $clv = $this->pickListingVariant(
                    $listing->variants,
                    $externalVariationId,
                    $variantId,
                );
                if ($clv !== null) {
                    $clvId = (int) $clv->id;
                    if ($variantId === null && $clv->variant_id !== null) {
                        $variantId = (int) $clv->variant_id;
                    }
                }
            }
        }

        if ($variantId === null && $sku !== null) {
            $clv = ChannelListingVariant::query()
                ->where('workspace_id', $workspaceId)
                ->where('sku_external', $sku)
                ->whereHas('listing', fn ($q) => $q->where('connection_id', $connectionId))
                ->orderBy('id')
                ->first();

            if ($clv !== null) {
                $clvId ??= (int) $clv->id;
                if ($clv->variant_id !== null) {
                    $variantId = (int) $clv->variant_id;
                }
            }
        }

        return [
            'variant_id' => $variantId,
            'channel_listing_variant_id' => $clvId,
            'match_status' => $variantId !== null ? 'matched' : 'unmatched',
        ];
    }

    /**
     * @param  Collection<int, ChannelListingVariant>  $variants
     */
    private function pickListingVariant(
        Collection $variants,
        ?string $externalVariationId,
        ?int $variantId,
    ): ?ChannelListingVariant {
        if ($variants->isEmpty()) {
            return null;
        }

        if ($externalVariationId !== null) {
            $byVariation = $variants->first(
                fn (ChannelListingVariant $clv) => (string) $clv->external_variation_id === $externalVariationId,
            );
            if ($byVariation !== null) {
                return $byVariation;
            }
        }

        if ($variantId !== null) {
            $byVariant = $variants->first(
                fn (ChannelListingVariant $clv) => $clv->variant_id !== null && (int) $clv->variant_id === $variantId,
            );
            if ($byVariant !== null) {
                return $byVariant;
            }
        }

        if ($variants->count() === 1) {
            return $variants->first();
        }

        return null;
    }
}
