<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\Actions\ArchiveProduct;
use App\Domain\Catalog\Actions\CreateProductWithVariants;
use App\Domain\Catalog\Actions\RestoreProduct;
use App\Domain\Catalog\Actions\UpdateProductWithVariants;
use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Concerns\RespondsForEmbeddedEditor;
use App\Http\Controllers\Concerns\RespondsWithFilteredSelection;
use App\Http\Filters\Products\ProductFilterRegistry;
use App\Http\Requests\Products\IndexFilterRequest;
use App\Models\ChannelListing;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductsController extends Controller
{
    use RespondsForEmbeddedEditor;
    use RespondsWithFilteredSelection;

    public function index(IndexFilterRequest $request, ProductFilterRegistry $filterRegistry): Response
    {
        $filters = $request->filters();
        $archived = (bool) $filters['archived'];
        $withoutCost = (bool) $filters['without_cost'];
        $workspaceId = TenantContext::id();

        $query = Product::query()->where('workspace_id', $workspaceId);
        $filterRegistry->apply($query, $filters);

        $products = $query
            ->with([
                'variants' => fn ($query) => $archived
                    ? $query->withTrashed()
                    : $query,
                'variants.channelListingVariants.listing',
            ])
            ->withCount([
                'variants' => fn ($query) => $archived
                    ? $query->withTrashed()
                    : $query,
                'channelListingVariants as matched_listings_count',
                'variants as variants_without_cost_count' => fn ($query) => $query
                    ->when($archived, fn ($q) => $q->withTrashed())
                    ->whereDoesntHave('costLayers'),
            ])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(function (Product $product) {
                $product->setAttribute(
                    'matched_listings',
                    $product->variants
                        ->flatMap(fn ($variant) => $variant->channelListingVariants)
                        ->map(function ($listingVariant) {
                            $listing = $listingVariant->listing;

                            return [
                                'id' => $listingVariant->id,
                                'title' => $listing?->title,
                                'external_item_id' => $listing?->external_item_id,
                                'permalink' => $listing?->permalink,
                                'provider' => $listing?->provider,
                            ];
                        })
                        ->values()
                        ->all(),
                );

                $product->setAttribute(
                    'missing_cost',
                    ((int) $product->variants_without_cost_count) > 0,
                );

                // Avoid shipping nested relation trees to the index page.
                $product->unsetRelation('variants');

                return $product;
            });

        return Inertia::render('Products/Index', [
            'products' => $products,
            'archived' => $archived,
            'without_cost' => $withoutCost,
            'missing_cost_count' => Product::countWithoutCostForWorkspace((int) $workspaceId),
        ]);
    }

    public function allIds(IndexFilterRequest $request, ProductFilterRegistry $filterRegistry): JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $query = Product::query()->where('workspace_id', $workspaceId);
        $filterRegistry->apply($query, $request->filters());

        return $this->selectionAllIds($query);
    }

    public function filteredSums(IndexFilterRequest $request, ProductFilterRegistry $filterRegistry): JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $query = Product::query()->where('workspace_id', $workspaceId);
        $filterRegistry->apply($query, $request->filters());

        return $this->selectionFilteredSums($query, []);
    }

    public function create(): Response
    {
        return Inertia::render('Products/Create');
    }

    public function store(Request $request, CreateProductWithVariants $create): RedirectResponse
    {
        $workspaceId = TenantContext::id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.sku' => [
                'required',
                'string',
                'max:255',
                'distinct',
                Rule::unique('variants', 'sku')->where(
                    fn ($query) => $query->where('workspace_id', $workspaceId),
                ),
            ],
            'variants.*.name' => ['nullable', 'string', 'max:255'],
            'variants.*.gtin' => ['nullable', 'string', 'max:64'],
            'variants.*.status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
        ]);

        $create->execute($workspaceId, $data);

        return redirect()
            ->route('products.index')
            ->with('success', 'Product created.');
    }

    public function edit(Request $request, Product $product): Response|JsonResponse
    {
        abort_unless($product->workspace_id === TenantContext::id(), 404);

        $payload = $this->buildEditPayload($product);

        if ($this->isEmbeddedRequest($request)) {
            return response()->json($payload);
        }

        return Inertia::render('Products/Edit', $payload);
    }

    public function update(
        Request $request,
        Product $product,
        UpdateProductWithVariants $update,
    ): RedirectResponse|JsonResponse {
        abort_unless($product->workspace_id === TenantContext::id(), 404);

        $workspaceId = TenantContext::id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.id' => [
                'nullable',
                'integer',
                Rule::exists('variants', 'id')->where(
                    fn ($query) => $query
                        ->where('workspace_id', $workspaceId)
                        ->where('product_id', $product->id)
                        ->whereNull('deleted_at'),
                ),
            ],
            'variants.*.sku' => ['required', 'string', 'max:255', 'distinct'],
            'variants.*.name' => ['nullable', 'string', 'max:255'],
            'variants.*.gtin' => ['nullable', 'string', 'max:64'],
            'variants.*.status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
        ]);

        $skuRules = [];
        foreach ($data['variants'] as $index => $variant) {
            $unique = Rule::unique('variants', 'sku')->where(
                fn ($query) => $query->where('workspace_id', $workspaceId),
            );

            if (! empty($variant['id'])) {
                $unique = $unique->ignore($variant['id']);
            }

            $skuRules["variants.{$index}.sku"] = [$unique];
        }
        $request->validate($skuRules);

        $update->execute($product, $data);

        return $this->respondEmbeddedSave(
            $request,
            'Product updated.',
            ['product' => $this->buildEditPayload($product->fresh())['product']],
            'products.index',
        );
    }

    public function destroy(Product $product, ArchiveProduct $archive): RedirectResponse
    {
        abort_unless($product->workspace_id === TenantContext::id(), 404);

        $archive->execute($product);

        return redirect()
            ->route('products.index')
            ->with('success', 'Product archived.');
    }

    public function restore(int $product, RestoreProduct $restore): RedirectResponse
    {
        $model = Product::onlyTrashed()
            ->where('workspace_id', TenantContext::id())
            ->whereKey($product)
            ->firstOrFail();

        $restore->execute($model);

        return redirect()
            ->route('products.index')
            ->with('success', 'Product restored.');
    }

    /**
     * @return array{
     *     product: array<string, mixed>,
     *     matchedListings: Collection<int, array<string, mixed>>,
     *     listing_pictures: list<array<string, mixed>>,
     *     pending_images: list<array<string, mixed>>,
     *     picture_gallery: array<string, mixed>
     * }
     */
    private function buildEditPayload(Product $product): array
    {
        $product->load([
            'variants.channelListingVariants.listing',
            'images.file',
        ]);

        $matchedListings = $product->variants
            ->flatMap(function ($variant) {
                return $variant->channelListingVariants->map(function ($listingVariant) use ($variant) {
                    $listing = $listingVariant->listing;

                    return [
                        'id' => $listingVariant->id,
                        'variant_id' => $variant->id,
                        'variant_sku' => $variant->sku,
                        'variant_name' => $variant->name,
                        'sku_external' => $listingVariant->sku_external,
                        'external_variation_id' => $listingVariant->external_variation_id,
                        'attribute_combinations' => $listingVariant->attribute_combinations,
                        'picture_ids' => $listingVariant->picture_ids,
                        'listing_id' => $listing?->id,
                        'title' => $listing?->title,
                        'external_item_id' => $listing?->external_item_id,
                        'permalink' => $listing?->permalink,
                        'provider' => $listing?->provider,
                        'status' => $listing?->status,
                        'pictures' => $this->normalizeListingPictures($listing),
                    ];
                });
            })
            ->values();

        $listingPictures = [];
        $seenUrls = [];
        foreach ($matchedListings as $row) {
            foreach ($row['pictures'] as $picture) {
                $url = $picture['url'] ?? null;
                if (! is_string($url) || $url === '' || isset($seenUrls[$url])) {
                    continue;
                }
                $seenUrls[$url] = true;
                $listingPictures[] = $picture;
            }
        }

        $pendingImages = $product->images
            ->map(fn (ProductImage $image) => [
                'id' => $image->id,
                'url' => $image->url(),
                'publish_status' => $image->publish_status,
                'sort_order' => $image->sort_order,
                'channel_listing_id' => $image->channel_listing_id,
                'external_picture_id' => $image->external_picture_id,
                'source' => 'upload',
                'pending' => $image->isPending(),
            ])
            ->values()
            ->all();

        return [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'status' => $product->status,
                'variants' => $product->variants->map(fn ($variant) => [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'name' => $variant->name,
                    'gtin' => $variant->gtin,
                ])->values()->all(),
            ],
            'matchedListings' => $matchedListings,
            'listing_pictures' => $listingPictures,
            'pending_images' => $pendingImages,
            'picture_gallery' => $this->buildPictureGallery(
                $matchedListings->all(),
                $pendingImages,
                $product->variants->count(),
            ),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $matchedListings
     * @param  list<array<string, mixed>>  $pendingImages
     * @return array{cover_url: string|null, groups: list<array<string, mixed>>, needs_resync: bool}
     */
    private function buildPictureGallery(array $matchedListings, array $pendingImages, int $canonicalVariantCount = 0): array
    {
        $byId = [];
        foreach ($matchedListings as $row) {
            foreach ($row['pictures'] ?? [] as $picture) {
                $id = $picture['id'] ?? null;
                $url = $picture['url'] ?? null;
                if (! is_string($url) || $url === '') {
                    continue;
                }
                $key = is_string($id) && $id !== '' ? $id : $url;
                $byId[$key] = $picture;
            }
        }

        $assignedIds = [];
        $groups = [];
        $hasPictureIds = false;

        foreach ($matchedListings as $row) {
            $pictureIds = is_array($row['picture_ids'] ?? null) ? $row['picture_ids'] : [];
            if ($pictureIds === []) {
                continue;
            }
            $hasPictureIds = true;
            $pics = [];
            foreach ($pictureIds as $pictureId) {
                $pid = (string) $pictureId;
                $picture = $byId[$pid] ?? null;
                if ($picture === null) {
                    continue;
                }
                $assignedIds[$pid] = true;
                $pics[] = $picture;
            }
            if ($pics === []) {
                continue;
            }

            $label = $this->variantPictureLabel($row);
            $groups[] = [
                'key' => 'variant-'.($row['variant_id'] ?? $row['id']),
                'kind' => 'variant',
                'label' => $label,
                'sku' => $row['variant_sku'] ?? $row['sku_external'] ?? null,
                'variant_id' => $row['variant_id'] ?? null,
                'listing_id' => $row['listing_id'] ?? null,
                'pictures' => $pics,
            ];
        }

        $shared = [];
        foreach ($byId as $key => $picture) {
            $id = $picture['id'] ?? null;
            $lookup = is_string($id) && $id !== '' ? $id : ($picture['url'] ?? $key);
            if (isset($assignedIds[$lookup])) {
                continue;
            }
            $shared[] = $picture;
        }

        if ($shared !== []) {
            $groups[] = [
                'key' => 'shared',
                'kind' => 'shared',
                'label' => $hasPictureIds ? 'Otras de la publicación' : 'Publicación',
                'sku' => null,
                'variant_id' => null,
                'listing_id' => $matchedListings[0]['listing_id'] ?? null,
                'pictures' => $shared,
            ];
        }

        if ($pendingImages !== []) {
            $groups[] = [
                'key' => 'pending',
                'kind' => 'pending',
                'label' => 'Pendientes de publicar',
                'sku' => null,
                'variant_id' => null,
                'listing_id' => null,
                'pictures' => $pendingImages,
            ];
        }

        $cover = $groups[0]['pictures'][0]['url'] ?? null;

        return [
            'cover_url' => is_string($cover) ? $cover : null,
            'groups' => $groups,
            'needs_resync' => $canonicalVariantCount > 1
                && count($matchedListings) > 0
                && ! $hasPictureIds
                && count($byId) > 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function variantPictureLabel(array $row): string
    {
        $attrs = $row['attribute_combinations'] ?? null;
        if (is_array($attrs) && $attrs !== []) {
            $parts = [];
            foreach ($attrs as $attr) {
                $value = $attr['value_name'] ?? null;
                if (is_string($value) && $value !== '') {
                    $parts[] = $value;
                }
            }
            if ($parts !== []) {
                return implode(' · ', $parts);
            }
        }

        $name = $row['variant_name'] ?? null;
        if (is_string($name) && $name !== '' && strtolower($name) !== 'default') {
            return $name;
        }

        $sku = $row['variant_sku'] ?? $row['sku_external'] ?? null;

        return is_string($sku) && $sku !== '' ? $sku : 'Variante';
    }

    /**
     * @return list<array{id: string|null, url: string, size: string|null, source: string, pending: bool, listing_id: int|null}>
     */
    private function normalizeListingPictures(?ChannelListing $listing): array
    {
        if ($listing === null || ! is_array($listing->pictures)) {
            return [];
        }

        $out = [];
        foreach ($listing->pictures as $picture) {
            $url = null;
            $id = null;
            $size = null;
            if (is_string($picture)) {
                $url = $picture;
            } elseif (is_array($picture)) {
                $url = $picture['url'] ?? $picture['secure_url'] ?? $picture['source'] ?? null;
                $id = isset($picture['id']) ? (string) $picture['id'] : null;
                $size = isset($picture['size']) ? (string) $picture['size'] : null;
            }
            if (! is_string($url) || $url === '') {
                continue;
            }
            $out[] = [
                'id' => $id,
                'url' => $url,
                'size' => $size,
                'source' => 'listing',
                'pending' => false,
                'listing_id' => $listing->id,
            ];
        }

        return $out;
    }
}
