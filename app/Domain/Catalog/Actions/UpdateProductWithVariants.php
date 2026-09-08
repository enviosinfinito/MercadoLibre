<?php

namespace App\Domain\Catalog\Actions;

use App\Models\ChannelListingVariant;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateProductWithVariants
{
    public function __construct(
        private readonly MatchListingToVariant $matchListingToVariant,
    ) {}

    /**
     * @param  array{
     *   name:string,
     *   description?:string|null,
     *   status?:string,
     *   variants:list<array{
     *     id?:int|null,
     *     sku:string,
     *     name?:string|null,
     *     gtin?:string|null,
     *     status?:string
     *   }>
     * }  $input
     */
    public function execute(Product $product, array $input): Product
    {
        return DB::transaction(function () use ($product, $input) {
            $product->update([
                'name' => $input['name'],
                'description' => $input['description'] ?? null,
                'status' => $input['status'] ?? $product->status,
            ]);

            $workspaceId = (int) $product->workspace_id;
            $keptIds = [];

            foreach ($input['variants'] as $variantInput) {
                $variantId = isset($variantInput['id']) ? (int) $variantInput['id'] : null;

                if ($variantId !== null) {
                    $variant = Variant::query()
                        ->where('product_id', $product->id)
                        ->whereKey($variantId)
                        ->first();

                    if ($variant === null) {
                        throw ValidationException::withMessages([
                            'variants' => 'One or more variants do not belong to this product.',
                        ]);
                    }

                    $previousSku = $variant->sku;

                    $variant->update([
                        'sku' => $variantInput['sku'],
                        'name' => $variantInput['name'] ?? null,
                        'gtin' => $variantInput['gtin'] ?? null,
                        'status' => $variantInput['status'] ?? $variant->status,
                    ]);

                    $keptIds[] = $variant->id;

                    if ($previousSku !== $variant->sku) {
                        $this->rematchUnmatchedBySku($workspaceId, $variant);
                    }

                    continue;
                }

                $variant = $product->variants()->create([
                    'workspace_id' => $workspaceId,
                    'sku' => $variantInput['sku'],
                    'name' => $variantInput['name'] ?? null,
                    'gtin' => $variantInput['gtin'] ?? null,
                    'status' => $variantInput['status'] ?? 'active',
                ]);

                $keptIds[] = $variant->id;
                $this->rematchUnmatchedBySku($workspaceId, $variant);
            }

            $toRemove = Variant::query()
                ->where('product_id', $product->id)
                ->whereNotIn('id', $keptIds)
                ->get();

            foreach ($toRemove as $variant) {
                $this->unmatchVariant($variant->id);
                $variant->delete();
            }

            return $product->fresh()->load('variants');
        });
    }

    private function rematchUnmatchedBySku(int $workspaceId, Variant $variant): void
    {
        $unmatched = ChannelListingVariant::query()
            ->where('workspace_id', $workspaceId)
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

    private function unmatchVariant(int $variantId): void
    {
        ChannelListingVariant::query()
            ->where('variant_id', $variantId)
            ->update(['variant_id' => null]);
    }
}
