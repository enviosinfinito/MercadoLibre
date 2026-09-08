<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\ChannelListing;
use App\Models\ChannelListingVariant;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductSlideContextController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $productId = $request->filled('product_id') ? (int) $request->input('product_id') : null;
        $listingId = $request->filled('listing_id') ? (int) $request->input('listing_id') : null;
        $mlItemId = $request->filled('ml_item_id')
            ? trim((string) $request->input('ml_item_id'))
            : null;

        abort_unless($productId !== null || $listingId !== null || filled($mlItemId), 422);

        $listing = null;
        $product = null;

        if ($listingId !== null) {
            $listing = ChannelListing::query()
                ->where('workspace_id', $workspaceId)
                ->with([
                    'connection:id,provider,external_user_id,display_name,color',
                    'variants.variant:id,product_id,sku',
                ])
                ->findOrFail($listingId);
        } elseif (filled($mlItemId)) {
            $listing = ChannelListing::query()
                ->where('workspace_id', $workspaceId)
                ->where('external_item_id', $mlItemId)
                ->with([
                    'connection:id,provider,external_user_id,display_name,color',
                    'variants.variant:id,product_id,sku',
                ])
                ->first();
        }

        if ($productId !== null) {
            $product = Product::query()
                ->where('workspace_id', $workspaceId)
                ->findOrFail($productId);
        } elseif ($listing !== null) {
            $resolvedProductId = $listing->product_id
                ?: $listing->variants
                    ->first(fn (ChannelListingVariant $v) => $v->variant?->product_id !== null)
                    ?->variant
                    ?->product_id;

            if ($resolvedProductId) {
                $product = Product::query()
                    ->where('workspace_id', $workspaceId)
                    ->find($resolvedProductId);
            }
        }

        if ($listing === null && $product !== null) {
            $listing = ChannelListing::query()
                ->where('workspace_id', $workspaceId)
                ->where(function ($q) use ($product): void {
                    $q->where('product_id', $product->id)
                        ->orWhereHas(
                            'variants',
                            fn ($v) => $v->whereHas(
                                'variant',
                                fn ($canonical) => $canonical->where('product_id', $product->id),
                            ),
                        );
                })
                ->with([
                    'connection:id,provider,external_user_id,display_name,color',
                    'variants.variant:id,product_id,sku',
                ])
                ->orderByDesc('updated_at')
                ->first();
        }

        $resolvedProductId = $product?->id;
        $resolvedListingId = $listing?->id;
        $resolvedMlItemId = $listing?->external_item_id ?: $mlItemId;

        $title = $listing?->title
            ?: $product?->name
            ?: ($resolvedMlItemId ? (string) $resolvedMlItemId : 'Producto');

        $image = $this->firstPictureUrl($listing?->pictures);

        $returnsKey = $resolvedProductId !== null
            ? (string) $resolvedProductId
            : ($resolvedMlItemId ? 'ml:'.$resolvedMlItemId : null);

        return response()->json([
            'title' => $title,
            'image' => $image,
            'product_id' => $resolvedProductId,
            'listing_id' => $resolvedListingId,
            'ml_item_id' => $resolvedMlItemId,
            'returns_key' => $returnsKey,
            'connection' => $listing?->connection ? [
                'id' => $listing->connection->id,
                'provider' => $listing->connection->provider,
                'display_name' => $listing->connection->display_name,
                'external_user_id' => $listing->connection->external_user_id,
                'color' => $listing->connection->color,
            ] : null,
            'tabs' => [
                'publication' => $resolvedListingId !== null || filled($resolvedMlItemId),
                'product' => $resolvedProductId !== null,
                'sales' => $resolvedProductId !== null,
                'ads' => $resolvedProductId !== null || filled($resolvedMlItemId),
                'questions' => $resolvedProductId !== null || filled($resolvedMlItemId),
                'returns' => filled($returnsKey),
            ],
        ]);
    }

    /**
     * @param  array<int, mixed>|null  $pictures
     */
    private function firstPictureUrl(?array $pictures): ?string
    {
        if ($pictures === null || $pictures === []) {
            return null;
        }

        $first = $pictures[0] ?? null;
        if (is_string($first) && $first !== '') {
            return $first;
        }
        if (! is_array($first)) {
            return null;
        }

        foreach (['secure_url', 'url', 'source'] as $key) {
            if (! empty($first[$key]) && is_string($first[$key])) {
                return $first[$key];
            }
        }

        return null;
    }
}
