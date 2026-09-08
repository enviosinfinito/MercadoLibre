<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Actions\ReceiveInventory;
use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Filters\Inventory\ReceiptFilterRegistry;
use App\Http\Requests\Inventory\ReceiptIndexFilterRequest;
use App\Models\CostLayer;
use App\Models\SupplierPurchaseOrder;
use App\Models\SupplierPurchaseOrderLine;
use App\Models\Variant;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReceiptsController extends Controller
{
    public function index(ReceiptIndexFilterRequest $request, ReceiptFilterRegistry $filterRegistry): Response
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $filters = $request->filters();

        $query = CostLayer::query()
            ->where('workspace_id', $workspaceId)
            ->where('source_type', 'receipt')
            ->orderByDesc('received_at')
            ->orderByDesc('id');

        $filterRegistry->apply($query, $filters, $workspaceId);

        $layers = $query->paginate(50)->withQueryString();

        $variantIds = $layers->getCollection()->pluck('variant_id')->unique()->filter();
        $warehouseIds = $layers->getCollection()->pluck('warehouse_id')->unique()->filter();

        $variants = Variant::query()
            ->whereIn('id', $variantIds)
            ->get(['id', 'sku', 'name'])
            ->keyBy('id');

        $warehousesMap = Warehouse::query()
            ->whereIn('id', $warehouseIds)
            ->get(['id', 'code', 'name'])
            ->keyBy('id');

        $rows = $layers->getCollection()->map(function (CostLayer $layer) use ($variants, $warehousesMap) {
            $variant = $variants->get($layer->variant_id);
            $wh = $warehousesMap->get($layer->warehouse_id);

            return [
                'id' => $layer->id,
                'qty_original' => (string) $layer->qty_original,
                'qty_remaining' => (string) $layer->qty_remaining,
                'qty_expected' => $layer->qty_expected !== null ? (string) $layer->qty_expected : null,
                'purchase_order_line_id' => $layer->purchase_order_line_id,
                'unit_cost_amount' => (string) $layer->unit_cost_amount,
                'unit_cost_currency' => $layer->unit_cost_currency,
                'notes' => $layer->notes,
                'received_at' => optional($layer->received_at)?->toIso8601String(),
                'variant' => $variant ? [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'name' => $variant->name,
                ] : null,
                'warehouse' => $wh ? [
                    'id' => $wh->id,
                    'code' => $wh->code,
                    'name' => $wh->name,
                ] : null,
            ];
        });

        $layers->setCollection($rows);

        $poQuery = SupplierPurchaseOrder::query()
            ->where('workspace_id', $workspaceId)
            ->with(['lines.variant:id,sku,name'])
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->limit(50);

        if (! empty($filters['variant_id'])) {
            $poQuery->whereHas(
                'lines',
                fn ($q) => $q->where('variant_id', (int) $filters['variant_id']),
            );
        }

        $purchaseOrders = $poQuery->get()->map(fn (SupplierPurchaseOrder $po) => [
            'id' => $po->id,
            'supplier_name' => $po->supplier_name,
            'status' => $po->status,
            'ordered_at' => optional($po->ordered_at)?->toIso8601String(),
            'lines_count' => $po->lines->count(),
            'qty_ordered' => $po->lines->reduce(
                fn (string $carry, SupplierPurchaseOrderLine $line) => bcadd($carry, (string) $line->qty_ordered, 6),
                '0',
            ),
            'qty_received' => $po->lines->reduce(
                fn (string $carry, SupplierPurchaseOrderLine $line) => bcadd($carry, (string) $line->qty_received, 6),
                '0',
            ),
            'lines' => $po->lines->map(fn (SupplierPurchaseOrderLine $line) => [
                'id' => $line->id,
                'variant_id' => $line->variant_id,
                'sku' => $line->variant?->sku,
                'qty_ordered' => (string) $line->qty_ordered,
                'qty_received' => (string) $line->qty_received,
                'variance' => $line->varianceQty(),
            ]),
        ]);

        return Inertia::render('Inventory/Receipts', [
            'receipts' => $layers,
            'purchase_orders' => $purchaseOrders,
            'warehouses' => Warehouse::query()
                ->where('workspace_id', $workspaceId)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        $workspaceId = TenantContext::id();

        return Inertia::render('Inventory/ReceiptCreate', [
            'variants' => Variant::query()->where('workspace_id', $workspaceId)->orderBy('sku')->limit(200)->get(['id', 'sku', 'name']),
            'warehouses' => Warehouse::query()->where('workspace_id', $workspaceId)->get(['id', 'code', 'name', 'is_default']),
        ]);
    }

    public function store(Request $request, ReceiveInventory $receive): RedirectResponse
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost_amount' => ['required', 'numeric', 'gte:0'],
            'unit_cost_currency' => ['required', 'string', 'size:3'],
            'fx_rate' => ['nullable', 'numeric', 'gt:0'],
            'reporting_currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $receive->execute(TenantContext::id(), $data);

        return redirect()->route('inventory.receipts.index')->with('success', 'Inventario recibido');
    }
}
