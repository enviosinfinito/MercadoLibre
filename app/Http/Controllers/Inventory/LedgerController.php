<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Filters\Inventory\LedgerFilterRegistry;
use App\Http\Requests\Inventory\LedgerIndexFilterRequest;
use App\Models\InventoryLedger;
use App\Models\Variant;
use App\Models\Warehouse;
use Inertia\Inertia;
use Inertia\Response;

class LedgerController extends Controller
{
    public function index(LedgerIndexFilterRequest $request, LedgerFilterRegistry $filterRegistry): Response
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $filters = $request->filters();

        $query = InventoryLedger::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        $filterRegistry->apply($query, $filters, $workspaceId);

        $entries = $query->paginate(50)->withQueryString();

        $variantIds = $entries->getCollection()->pluck('variant_id')->unique()->filter();
        $warehouseIds = $entries->getCollection()->pluck('warehouse_id')->unique()->filter();

        $variants = Variant::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $variantIds)
            ->get(['id', 'sku', 'name'])
            ->keyBy('id');

        $warehousesMap = Warehouse::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $warehouseIds)
            ->get(['id', 'code', 'name'])
            ->keyBy('id');

        $rows = $entries->getCollection()->map(function (InventoryLedger $entry) use ($variants, $warehousesMap) {
            $variant = $variants->get($entry->variant_id);
            $wh = $warehousesMap->get($entry->warehouse_id);

            return [
                'id' => $entry->id,
                'movement_type' => $entry->movement_type,
                'quantity_delta' => (string) $entry->quantity_delta,
                'quantity_after' => $entry->quantity_after !== null ? (string) $entry->quantity_after : null,
                'occurred_at' => optional($entry->occurred_at)?->toIso8601String(),
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
                'meta' => $entry->meta,
            ];
        });

        $entries->setCollection($rows);

        $warehouses = Warehouse::query()
            ->where('workspace_id', $workspaceId)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return Inertia::render('Inventory/Ledger', [
            'entries' => $entries,
            'warehouses' => $warehouses,
            'filters' => $filters,
            'movement_types' => [
                'receive',
                'reserve',
                'release',
                'fulfill',
                'adjust',
                'transfer_out',
                'transfer_in',
                'ship_to_full',
                'return_from_full',
            ],
        ]);
    }
}
