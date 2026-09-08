<?php

namespace App\Domain\Catalog\Actions;

use App\Jobs\SyncListingPurchaseExperienceJob;
use App\Models\ChannelListing;
use App\Models\ChannelListingVariant;
use App\Models\Connection;
use Illuminate\Support\Facades\DB;

final class UpsertChannelListing
{
    public function __construct(
        private readonly MatchListingToVariant $matchListingToVariant,
        private readonly AdoptListingAsCanonicalProduct $adoptListingAsCanonicalProduct,
    ) {}

    /**
     * @param  array<string, mixed>  $item  Mercado Libre item payload
     * @param  array<string, bool>  $include  Field groups from sync profile
     */
    public function execute(Connection $connection, array $item, array $include = []): ChannelListing
    {
        $externalItemId = isset($item['id']) ? (string) $item['id'] : '';
        if ($externalItemId === '') {
            throw new \InvalidArgumentException('Mercado Libre item id is required.');
        }

        $include = $this->normalizeInclude($include);

        $listing = DB::transaction(function () use ($connection, $item, $externalItemId, $include) {
            $listingAttrs = [
                'connection_id' => $connection->id,
                'title' => isset($item['title']) ? (string) $item['title'] : null,
                'status' => isset($item['status']) ? (string) $item['status'] : 'active',
                'category_id' => isset($item['category_id']) ? (string) $item['category_id'] : null,
                'category_name' => $this->extractCategoryName($item),
                'permalink' => isset($item['permalink']) ? (string) $item['permalink'] : null,
                'logistic_type' => $this->extractLogisticType($item),
                'inventory_id' => isset($item['inventory_id']) ? (string) $item['inventory_id'] : null,
                'shipping_meta' => $this->extractShippingMeta($item),
            ];

            if ($include['pictures']) {
                $listingAttrs['pictures'] = $this->extractPictures($item);
            }

            if ($include['description'] && array_key_exists('description', $item)) {
                $listingAttrs['description'] = $this->extractDescription($item['description']);
            }

            if ($include['attributes']) {
                $listingAttrs['attributes_meta'] = isset($item['attributes']) && is_array($item['attributes'])
                    ? $item['attributes']
                    : null;
            }

            if (isset($item['raw_snapshot_id'])) {
                $listingAttrs['raw_snapshot_id'] = (int) $item['raw_snapshot_id'];
            }

            if (isset($item['last_updated']) && is_string($item['last_updated'])) {
                try {
                    $listingAttrs['external_updated_at'] = \Illuminate\Support\Carbon::parse($item['last_updated']);
                } catch (\Throwable) {
                    // ignore unparseable timestamps
                }
            }

            if (isset($item['content_checksum']) && is_string($item['content_checksum'])) {
                $listingAttrs['content_checksum'] = $item['content_checksum'];
            }

            $listing = ChannelListing::query()->updateOrCreate(
                [
                    'workspace_id' => $connection->workspace_id,
                    'provider' => $connection->provider,
                    'external_item_id' => $externalItemId,
                ],
                $listingAttrs,
            );

            $variationRows = $this->extractVariationRows($item, $include);

            foreach ($variationRows as $row) {
                $variantAttrs = [
                    'workspace_id' => $connection->workspace_id,
                    'sku_external' => $row['sku_external'],
                    'attribute_combinations' => $row['attribute_combinations'],
                    'status' => $row['status'],
                    'user_product_id' => $row['user_product_id'],
                    'inventory_id' => $row['inventory_id'],
                ];

                if ($include['pictures']) {
                    $variantAttrs['picture_ids'] = $row['picture_ids'];
                }

                if ($include['price']) {
                    $variantAttrs['price_amount'] = $row['price_amount'];
                    $variantAttrs['currency_code'] = $row['currency_code'];
                }

                if ($include['stock']) {
                    $variantAttrs['available_quantity'] = $row['available_quantity'];
                }

                $listingVariant = ChannelListingVariant::query()->updateOrCreate(
                    [
                        'channel_listing_id' => $listing->id,
                        'external_variation_id' => $row['external_variation_id'],
                    ],
                    $variantAttrs,
                );

                if ($listingVariant->variant_id === null && filled($listingVariant->sku_external)) {
                    $this->matchListingToVariant->execute($listingVariant);
                }
            }

            $listing = $listing->fresh(['variants']);

            if (
                $listing !== null
                && in_array((string) $listing->status, ['active', 'paused'], true)
                && $listing->variants->contains(fn ($variant) => $variant->variant_id === null)
            ) {
                $listing = $this->adoptListingAsCanonicalProduct->execute($listing);
            }

            return $listing;
        });

        if ($include['purchase_experience'] && $listing !== null) {
            SyncListingPurchaseExperienceJob::dispatch(
                (int) $connection->workspace_id,
                (int) $connection->id,
                (int) $listing->id,
            );
        }

        return $listing;
    }

    /**
     * @param  array<string, bool>  $include
     * @return array<string, bool>
     */
    private function normalizeInclude(array $include): array
    {
        return [
            'raw_snapshot' => (bool) ($include['raw_snapshot'] ?? true),
            'price' => (bool) ($include['price'] ?? false),
            'stock' => (bool) ($include['stock'] ?? false),
            'pictures' => (bool) ($include['pictures'] ?? false),
            'description' => (bool) ($include['description'] ?? false),
            'attributes' => (bool) ($include['attributes'] ?? false),
            'purchase_experience' => (bool) ($include['purchase_experience'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, bool>  $include
     * @return list<array{
     *   external_variation_id: ?string,
     *   sku_external: ?string,
     *   attribute_combinations: ?array,
     *   status: string,
     *   price_amount: ?string,
     *   currency_code: ?string,
     *   available_quantity: ?int,
     *   user_product_id: ?string,
     *   inventory_id: ?string
     * }>
     */
    private function extractVariationRows(array $item, array $include): array
    {
        $status = isset($item['status']) ? (string) $item['status'] : 'active';
        $currency = isset($item['currency_id']) ? (string) $item['currency_id'] : null;
        $variations = $item['variations'] ?? [];
        $itemUserProductId = isset($item['user_product_id']) ? (string) $item['user_product_id'] : null;
        $itemInventoryId = isset($item['inventory_id']) ? (string) $item['inventory_id'] : null;

        if (is_array($variations) && $variations !== []) {
            $rows = [];

            foreach ($variations as $variation) {
                if (! is_array($variation)) {
                    continue;
                }

                $variationId = isset($variation['id']) ? (string) $variation['id'] : null;
                if ($variationId === null || $variationId === '') {
                    continue;
                }

                $rows[] = [
                    'external_variation_id' => $variationId,
                    'sku_external' => $this->extractSku($variation) ?? $this->extractSku($item),
                    'attribute_combinations' => $this->extractAttributeCombinations($variation),
                    'picture_ids' => $this->extractPictureIds($variation),
                    'status' => $status,
                    'price_amount' => $include['price']
                        ? $this->extractPrice($variation['price'] ?? $item['price'] ?? null)
                        : null,
                    'currency_code' => $include['price'] ? $currency : null,
                    'available_quantity' => $include['stock']
                        ? $this->extractQty($variation['available_quantity'] ?? null)
                        : null,
                    'user_product_id' => isset($variation['user_product_id'])
                        ? (string) $variation['user_product_id']
                        : $itemUserProductId,
                    'inventory_id' => isset($variation['inventory_id'])
                        ? (string) $variation['inventory_id']
                        : $itemInventoryId,
                ];
            }

            if ($rows !== []) {
                return $rows;
            }
        }

        return [[
            'external_variation_id' => '0',
            'sku_external' => $this->extractSku($item),
            'attribute_combinations' => null,
            'picture_ids' => $this->extractPictureIds($item),
            'status' => $status,
            'price_amount' => $include['price'] ? $this->extractPrice($item['price'] ?? null) : null,
            'currency_code' => $include['price'] ? $currency : null,
            'available_quantity' => $include['stock']
                ? $this->extractQty($item['available_quantity'] ?? null)
                : null,
            'user_product_id' => $itemUserProductId,
            'inventory_id' => $itemInventoryId,
        ]];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<string>|null
     */
    private function extractPictureIds(array $source): ?array
    {
        $raw = $source['picture_ids'] ?? null;
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $ids = [];
        foreach ($raw as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            } elseif (is_numeric($id)) {
                $ids[] = (string) $id;
            }
        }

        return $ids === [] ? null : array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function extractCategoryName(array $item): ?string
    {
        if (isset($item['category_name']) && is_string($item['category_name']) && $item['category_name'] !== '') {
            return $item['category_name'];
        }

        $category = $item['category'] ?? null;
        if (is_array($category) && isset($category['name']) && is_string($category['name'])) {
            return $category['name'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $variation
     * @return list<array{id?: string, name?: string, value_id?: string|null, value_name?: string|null}>|null
     */
    private function extractAttributeCombinations(array $variation): ?array
    {
        $raw = $variation['attribute_combinations'] ?? null;
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $rows = [];
        foreach ($raw as $attr) {
            if (! is_array($attr)) {
                continue;
            }
            $rows[] = [
                'id' => isset($attr['id']) ? (string) $attr['id'] : null,
                'name' => isset($attr['name']) ? (string) $attr['name'] : null,
                'value_id' => isset($attr['value_id']) ? (string) $attr['value_id'] : null,
                'value_name' => isset($attr['value_name']) ? (string) $attr['value_name'] : null,
            ];
        }

        return $rows !== [] ? $rows : null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function extractLogisticType(array $item): ?string
    {
        $shipping = $item['shipping'] ?? null;
        if (is_array($shipping) && isset($shipping['logistic_type'])) {
            return (string) $shipping['logistic_type'];
        }

        return isset($item['logistic_type']) ? (string) $item['logistic_type'] : null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function extractShippingMeta(array $item): ?array
    {
        $shipping = $item['shipping'] ?? null;
        if (! is_array($shipping)) {
            return null;
        }

        return array_filter([
            'logistic_type' => $shipping['logistic_type'] ?? null,
            'mode' => $shipping['mode'] ?? null,
            'tags' => $shipping['tags'] ?? null,
            'free_shipping' => $shipping['free_shipping'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<array<string, mixed>>|null
     */
    private function extractPictures(array $item): ?array
    {
        $pictures = $item['pictures'] ?? null;
        if (! is_array($pictures)) {
            return null;
        }

        $normalized = [];
        foreach ($pictures as $picture) {
            if (! is_array($picture)) {
                continue;
            }
            $normalized[] = [
                'id' => $picture['id'] ?? null,
                'url' => $picture['secure_url'] ?? $picture['url'] ?? null,
                'size' => $picture['size'] ?? null,
            ];
        }

        return $normalized === [] ? null : $normalized;
    }

    private function extractDescription(mixed $description): ?string
    {
        if (is_string($description)) {
            return $description;
        }

        if (is_array($description)) {
            $plain = $description['plain_text'] ?? $description['text'] ?? null;

            return is_string($plain) ? $plain : null;
        }

        return null;
    }

    private function extractPrice(mixed $price): ?string
    {
        if ($price === null || $price === '') {
            return null;
        }

        return is_numeric($price) ? number_format((float) $price, 6, '.', '') : null;
    }

    private function extractQty(mixed $qty): ?int
    {
        if ($qty === null || $qty === '') {
            return null;
        }

        return is_numeric($qty) ? (int) $qty : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractSku(array $payload): ?string
    {
        foreach (['seller_custom_field', 'seller_sku', 'sku'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $attributes = $payload['attributes'] ?? null;
        if (is_array($attributes)) {
            foreach ($attributes as $attribute) {
                if (! is_array($attribute)) {
                    continue;
                }
                $id = strtoupper((string) ($attribute['id'] ?? ''));
                if (in_array($id, ['SELLER_SKU', 'SKU'], true)) {
                    $value = $attribute['value_name'] ?? $attribute['value_id'] ?? null;
                    if (is_string($value) && trim($value) !== '') {
                        return trim($value);
                    }
                }
            }
        }

        return null;
    }
}
