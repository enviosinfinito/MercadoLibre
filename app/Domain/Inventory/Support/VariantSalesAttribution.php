<?php

namespace App\Domain\Inventory\Support;

use App\Models\ChannelListingVariant;

/**
 * Resolve which canonical variant an order line belongs to.
 *
 * Priority: variant_id → channel_listing_variant_id → external_item_id+external_variation_id.
 */
final class VariantSalesAttribution
{
    /**
     * @param  list<int>  $variantIds
     * @return array{
     *     variant_id_set: array<int, true>,
     *     clv_id_to_variant: array<int, int>,
     *     external_pair_to_variant: array<string, int>
     * }
     */
    public static function mapsForVariants(int $workspaceId, array $variantIds): array
    {
        $variantIds = array_values(array_unique(array_filter(
            array_map('intval', $variantIds),
            fn (int $id) => $id > 0,
        )));

        $variantIdSet = array_fill_keys($variantIds, true);
        $clvIdToVariant = [];
        $externalPairToVariant = [];

        if ($variantIds === []) {
            return [
                'variant_id_set' => $variantIdSet,
                'clv_id_to_variant' => $clvIdToVariant,
                'external_pair_to_variant' => $externalPairToVariant,
            ];
        }

        $clvs = ChannelListingVariant::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('variant_id', $variantIds)
            ->with(['listing:id,external_item_id'])
            ->get(['id', 'variant_id', 'channel_listing_id', 'external_variation_id']);

        foreach ($clvs as $clv) {
            $vid = (int) $clv->variant_id;
            $clvIdToVariant[(int) $clv->id] = $vid;
            $extItem = $clv->listing?->external_item_id;
            $extVar = $clv->external_variation_id;
            if (is_string($extItem) && $extItem !== '' && $extVar !== null && (string) $extVar !== '') {
                $externalPairToVariant[$extItem.'|'.(string) $extVar] = $vid;
            }
        }

        return [
            'variant_id_set' => $variantIdSet,
            'clv_id_to_variant' => $clvIdToVariant,
            'external_pair_to_variant' => $externalPairToVariant,
        ];
    }

    /**
     * @param  array{
     *     variant_id_set: array<int, true>,
     *     clv_id_to_variant: array<int, int>,
     *     external_pair_to_variant: array<string, int>
     * }  $maps
     */
    public static function resolveVariantId(object $line, array $maps): ?int
    {
        $variantIdSet = $maps['variant_id_set'];
        $clvIdToVariant = $maps['clv_id_to_variant'];
        $externalPairToVariant = $maps['external_pair_to_variant'];

        $direct = isset($line->variant_id) && $line->variant_id !== null ? (int) $line->variant_id : null;
        if ($direct !== null && isset($variantIdSet[$direct])) {
            return $direct;
        }

        if (isset($line->channel_listing_variant_id) && $line->channel_listing_variant_id !== null) {
            $fromClv = $clvIdToVariant[(int) $line->channel_listing_variant_id] ?? null;
            if ($fromClv !== null) {
                return $fromClv;
            }
        }

        $extItem = isset($line->external_item_id) && is_string($line->external_item_id)
            ? $line->external_item_id
            : null;
        $extVar = isset($line->external_variation_id) && $line->external_variation_id !== null
            ? (string) $line->external_variation_id
            : null;
        if ($extItem !== null && $extVar !== null && $extVar !== '') {
            return $externalPairToVariant[$extItem.'|'.$extVar] ?? null;
        }

        return null;
    }
}
