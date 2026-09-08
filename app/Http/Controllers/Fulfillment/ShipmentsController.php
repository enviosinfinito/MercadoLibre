<?php

namespace App\Http\Controllers\Fulfillment;

use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Concerns\RespondsWithFilteredSelection;
use App\Http\Controllers\Controller;
use App\Http\Filters\Shipments\ShipmentFilterRegistry;
use App\Http\Requests\Shipments\IndexFilterRequest;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShipmentsController extends Controller
{
    use RespondsWithFilteredSelection;

    public function index(IndexFilterRequest $request, ShipmentFilterRegistry $filterRegistry): Response
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $filters = $request->filters();

        $query = Shipment::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('id');

        $filterRegistry->apply($query, $filters);

        $shipments = $query->paginate(25)->withQueryString();

        return Inertia::render('Fulfillment/Shipments/Index', [
            'shipments' => $shipments,
            'filters' => [
                'q' => $filters['q'] ?? '',
            ],
        ]);
    }

    public function allIds(IndexFilterRequest $request, ShipmentFilterRegistry $filterRegistry): JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $query = Shipment::query()->where('workspace_id', $workspaceId);
        $filterRegistry->apply($query, $request->filters());

        return $this->selectionAllIds($query);
    }

    public function filteredSums(IndexFilterRequest $request, ShipmentFilterRegistry $filterRegistry): JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $query = Shipment::query()->where('workspace_id', $workspaceId);
        $filterRegistry->apply($query, $request->filters());

        return $this->selectionFilteredSums($query, []);
    }

    public function show(Request $request, Shipment $shipment): JsonResponse
    {
        abort_unless((int) $shipment->workspace_id === TenantContext::id(), 404);

        $shipment->load(['order:id,external_order_id,status,currency_code,total_amount']);

        return response()->json([
            'shipment' => [
                'id' => $shipment->id,
                'status' => $shipment->status,
                'carrier' => $shipment->carrier,
                'tracking_number' => $shipment->tracking_number,
                'shipped_at' => $shipment->shipped_at,
                'delivered_at' => $shipment->delivered_at,
                'external_shipment_id' => $shipment->external_shipment_id,
                'order_id' => $shipment->order_id,
                'order' => $shipment->order,
                'meta' => $shipment->meta ?? [],
            ],
        ]);
    }
}
