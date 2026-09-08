<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class WarehousesController extends Controller
{
    public function index(): Response
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $warehouses = Warehouse::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->get()
            ->map(fn (Warehouse $w) => [
                'id' => $w->id,
                'code' => $w->code,
                'name' => $w->name,
                'is_default' => (bool) $w->is_default,
                'is_active' => (bool) $w->is_active,
                'created_at' => optional($w->created_at)?->toIso8601String(),
            ]);

        return Inertia::render('Inventory/Warehouses', [
            'warehouses' => $warehouses,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($workspaceId, $data) {
            $isDefault = (bool) ($data['is_default'] ?? false);

            if ($isDefault) {
                Warehouse::query()
                    ->where('workspace_id', $workspaceId)
                    ->update(['is_default' => false]);
            }

            Warehouse::query()->create([
                'workspace_id' => $workspaceId,
                'code' => strtoupper(trim($data['code'])),
                'name' => $data['name'],
                'is_default' => $isDefault,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);
        });

        return redirect()->route('inventory.warehouses.index')->with('success', 'Almacén creado');
    }

    public function update(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);
        abort_unless((int) $warehouse->workspace_id === (int) $workspaceId, 404);

        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:40'],
            'name' => ['sometimes', 'string', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($workspaceId, $warehouse, $data) {
            if (array_key_exists('is_default', $data) && $data['is_default']) {
                Warehouse::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('id', '!=', $warehouse->id)
                    ->update(['is_default' => false]);
            }

            $payload = [];
            if (isset($data['code'])) {
                $payload['code'] = strtoupper(trim($data['code']));
            }
            if (isset($data['name'])) {
                $payload['name'] = $data['name'];
            }
            if (array_key_exists('is_default', $data)) {
                $payload['is_default'] = (bool) $data['is_default'];
            }
            if (array_key_exists('is_active', $data)) {
                $payload['is_active'] = (bool) $data['is_active'];
            }

            $warehouse->update($payload);
        });

        return redirect()->route('inventory.warehouses.index')->with('success', 'Almacén actualizado');
    }
}
