<?php

namespace App\Domain\Catalog\Actions;

use App\Models\ChannelListingVariant;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

final class CreateProductWithVariants
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
     *     sku:string,
     *     name?:string|null,
     *     gtin?:string|null,
     *     status?:string
     *   }>
     * }  $input
     */
    public function execute(int $workspaceId, array $input): Product
    {
        return DB::transaction(function () use ($workspaceId, $input) {
            $product = Product::query()->create([
                'workspace_id' => $workspaceId,
                'name' => $input['name'],
                'description' => $input['description'] ?? null,
                'status' => $input['status'] ?? 'active',
            ]);

            foreach ($input['variants'] as $variantInput) {
                $variant = $product->variants()->create([
                    'workspace_id' => $workspaceId,
                    'sku' => $variantInput['sku'],
                    'name' => $variantInput['name'] ?? null,
                    'gtin' => $variantInput['gtin'] ?? null,
                    'status' => $variantInput['status'] ?? 'active',
                ]);

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

            return $product->load('variants');
        });
    }
}
