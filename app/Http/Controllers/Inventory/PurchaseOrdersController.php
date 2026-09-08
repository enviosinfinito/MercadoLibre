<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Actions\ReceiveInventory;
use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\SupplierPurchaseOrder;
use App\Models\SupplierPurchaseOrderLine;
use App\Models\Variant;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrdersController extends Controller
{
    public function create(): Response
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        return Inertia::render('Inventory/PurchaseOrderCreate', [
            'variants' => Variant::query()
                ->where('workspace_id', $workspaceId)
                ->orderBy('sku')
                ->limit(300)
                ->get(['id', 'sku', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $data = $request->validate([
            'supplier_name' => ['required', 'string', 'max:180'],
            'notes' => ['nullable', 'string', 'max:500'],
            'ordered_at' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.variant_id' => ['required', 'integer'],
            'lines.*.qty_ordered' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost_amount' => ['required', 'numeric', 'gte:0'],
            'lines.*.currency' => ['required', 'string', 'size:3'],
        ]);

        $variantIds = collect($data['lines'])->pluck('variant_id')->unique()->all();
        $validCount = Variant::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $variantIds)
            ->count();
        abort_unless($validCount === count($variantIds), 422);

        $po = DB::transaction(function () use ($workspaceId, $data) {
            $po = SupplierPurchaseOrder::query()->create([
                'workspace_id' => $workspaceId,
                'supplier_name' => $data['supplier_name'],
                'status' => SupplierPurchaseOrder::STATUS_ORDERED,
                'ordered_at' => $data['ordered_at'] ?? now(),
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['lines'] as $line) {
                SupplierPurchaseOrderLine::query()->create([
                    'workspace_id' => $workspaceId,
                    'supplier_purchase_order_id' => $po->id,
                    'variant_id' => (int) $line['variant_id'],
                    'qty_ordered' => (string) $line['qty_ordered'],
                    'qty_received' => '0',
                    'unit_cost_amount' => (string) $line['unit_cost_amount'],
                    'currency' => strtoupper((string) $line['currency']),
                ]);
            }

            return $po;
        });

        return redirect()
            ->route('inventory.purchase-orders.show', $po)
            ->with('success', 'Orden de compra creada');
    }

    public function show(SupplierPurchaseOrder $purchaseOrder): Response
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);
        abort_unless((int) $purchaseOrder->workspace_id === (int) $workspaceId, 404);

        $purchaseOrder->load(['lines.variant:id,sku,name']);

        return Inertia::render('Inventory/PurchaseOrderShow', [
            'purchase_order' => [
                'id' => $purchaseOrder->id,
                'supplier_name' => $purchaseOrder->supplier_name,
                'status' => $purchaseOrder->status,
                'ordered_at' => optional($purchaseOrder->ordered_at)?->toIso8601String(),
                'notes' => $purchaseOrder->notes,
                'lines' => $purchaseOrder->lines->map(fn (SupplierPurchaseOrderLine $line) => [
                    'id' => $line->id,
                    'variant_id' => $line->variant_id,
                    'qty_ordered' => (string) $line->qty_ordered,
                    'qty_received' => (string) $line->qty_received,
                    'variance' => $line->varianceQty(),
                    'unit_cost_amount' => (string) $line->unit_cost_amount,
                    'currency' => $line->currency,
                    'variant' => $line->variant ? [
                        'id' => $line->variant->id,
                        'sku' => $line->variant->sku,
                        'name' => $line->variant->name,
                    ] : null,
                ]),
            ],
            'warehouses' => Warehouse::query()
                ->where('workspace_id', $workspaceId)
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->get(['id', 'code', 'name', 'is_default']),
        ]);
    }

    public function receiveLine(
        Request $request,
        SupplierPurchaseOrder $purchaseOrder,
        SupplierPurchaseOrderLine $line,
        ReceiveInventory $receive,
    ): RedirectResponse {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);
        abort_unless((int) $purchaseOrder->workspace_id === (int) $workspaceId, 404);
        abort_unless((int) $line->supplier_purchase_order_id === (int) $purchaseOrder->id, 404);
        abort_unless((int) $line->workspace_id === (int) $workspaceId, 404);

        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0'],
            'warehouse_id' => ['nullable', 'integer'],
            'unit_cost_amount' => ['nullable', 'numeric', 'gte:0'],
            'unit_cost_currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $receive->execute((int) $workspaceId, [
            'variant_id' => (int) $line->variant_id,
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'quantity' => $data['quantity'],
            'unit_cost_amount' => $data['unit_cost_amount'] ?? $line->unit_cost_amount,
            'unit_cost_currency' => strtoupper((string) ($data['unit_cost_currency'] ?? $line->currency)),
            'reporting_currency' => strtoupper((string) ($data['unit_cost_currency'] ?? $line->currency)),
            'notes' => $data['notes'] ?? ('Recepción OC #'.$purchaseOrder->id),
            'purchase_order_line_id' => $line->id,
            'qty_expected' => (string) $line->qty_ordered,
        ]);

        return back()->with('success', 'Recepción registrada');
    }
}
