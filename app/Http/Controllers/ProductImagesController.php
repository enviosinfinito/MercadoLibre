<?php

namespace App\Http\Controllers;

use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Concerns\RespondsForEmbeddedEditor;
use App\Models\FileAsset;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductImagesController extends Controller
{
    use RespondsForEmbeddedEditor;

    public function store(Request $request, Product $product): JsonResponse
    {
        abort_unless((int) $product->workspace_id === TenantContext::id(), 404);

        $data = $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'channel_listing_id' => ['nullable', 'integer'],
        ]);

        $workspaceId = (int) $product->workspace_id;
        $uploaded = $data['image'];
        $disk = (string) config('filesystems.default', 's3');
        $ext = strtolower($uploaded->getClientOriginalExtension() ?: 'jpg');
        $directory = sprintf('workspaces/%d/products/%d', $workspaceId, $product->id);
        $filename = Str::uuid()->toString().'.'.$ext;
        $path = $uploaded->storeAs($directory, $filename, [
            'disk' => $disk,
            'visibility' => 'public',
        ]);

        abort_unless(is_string($path) && $path !== '', 500, 'No se pudo guardar el archivo.');

        $image = DB::transaction(function () use ($workspaceId, $product, $disk, $path, $uploaded, $data, $request) {
            $checksum = null;
            $realPath = $uploaded->getRealPath();
            if (is_string($realPath) && is_file($realPath)) {
                $checksum = hash_file('sha256', $realPath) ?: null;
            }

            $file = FileAsset::query()->create([
                'workspace_id' => $workspaceId,
                'disk' => $disk,
                'path' => $path,
                'mime' => $uploaded->getMimeType(),
                'bytes' => (int) $uploaded->getSize(),
                'checksum' => $checksum,
                'created_by' => $request->user()?->id,
            ]);

            $maxSort = (int) ProductImage::query()
                ->where('product_id', $product->id)
                ->max('sort_order');

            return ProductImage::query()->create([
                'workspace_id' => $workspaceId,
                'product_id' => $product->id,
                'file_id' => $file->id,
                'channel_listing_id' => $data['channel_listing_id'] ?? null,
                'sort_order' => $maxSort + 1,
                'publish_status' => ProductImage::STATUS_PENDING,
            ]);
        });

        $image->load('file');

        return response()->json([
            'success' => true,
            'image' => $this->serializeImage($image),
        ], 201);
    }

    public function destroy(Product $product, ProductImage $productImage): JsonResponse
    {
        abort_unless((int) $product->workspace_id === TenantContext::id(), 404);
        abort_unless((int) $productImage->product_id === (int) $product->id, 404);
        abort_unless((int) $productImage->workspace_id === (int) $product->workspace_id, 404);

        if ($productImage->publish_status === ProductImage::STATUS_SYNCED) {
            return response()->json([
                'message' => 'No se puede eliminar una imagen ya publicada desde aquí.',
            ], 422);
        }

        $productImage->load('file');
        $file = $productImage->file;

        DB::transaction(function () use ($productImage, $file): void {
            $productImage->delete();
            if ($file !== null) {
                try {
                    Storage::disk($file->disk)->delete($file->path);
                } catch (\Throwable) {
                    // ignore storage cleanup errors
                }
                $file->delete();
            }
        });

        return response()->json(['success' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeImage(ProductImage $image): array
    {
        return [
            'id' => $image->id,
            'url' => $image->url(),
            'publish_status' => $image->publish_status,
            'sort_order' => $image->sort_order,
            'channel_listing_id' => $image->channel_listing_id,
            'external_picture_id' => $image->external_picture_id,
            'source' => 'upload',
            'pending' => $image->isPending(),
        ];
    }
}
