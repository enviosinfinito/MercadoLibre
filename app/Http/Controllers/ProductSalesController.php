<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\Services\ProductSalesSummaryService;
use App\Domain\Shared\Support\TenantContext;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductSalesController extends Controller
{
    public function __construct(
        private readonly ProductSalesSummaryService $sales,
    ) {}

    public function show(Request $request, Product $product): JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);
        abort_unless((int) $product->workspace_id === (int) $workspaceId, 404);

        $periodPreset = $request->string('period')->toString() ?: 'all';

        $connectionIds = null;
        if ($request->filled('connection_id')) {
            $connectionIds = [(int) $request->input('connection_id')];
        }
        $rawIds = $request->input('connection_ids', []);
        if (is_array($rawIds) && $rawIds !== []) {
            $connectionIds = array_values(array_unique(array_map('intval', $rawIds)));
        }

        $payload = $this->sales->forProduct(
            $workspaceId,
            $product,
            $periodPreset,
            $connectionIds,
        );

        return response()->json($payload);
    }
}
