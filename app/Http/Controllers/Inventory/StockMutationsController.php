<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Actions\AdjustStock;
use App\Domain\Inventory\Actions\ReleaseStock;
use App\Domain\Inventory\Actions\ReturnStockFromFull;
use App\Domain\Inventory\Actions\ShipStockToFull;
use App\Domain\Inventory\Actions\TransferStock;
use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Variant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StockMutationsController extends Controller
{
    public function adjust(Request $request, Variant $variant, AdjustStock $adjust): RedirectResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);
        abort_unless((int) $variant->workspace_id === (int) $workspaceId, 404);

        $data = $request->validate([
            'warehouse_id' => ['nullable', 'integer'],
            'quantity_delta' => ['required', 'numeric', 'not_in:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $adjust->execute((int) $workspaceId, [
            'variant_id' => $variant->id,
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'quantity_delta' => $data['quantity_delta'],
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'Ajuste aplicado');
    }

    public function transfer(Request $request, Variant $variant, TransferStock $transfer): RedirectResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);
        abort_unless((int) $variant->workspace_id === (int) $workspaceId, 404);

        $data = $request->validate([
            'from_warehouse_id' => ['required', 'integer'],
            'to_warehouse_id' => ['required', 'integer', 'different:from_warehouse_id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $transfer->execute((int) $workspaceId, [
            'variant_id' => $variant->id,
            'from_warehouse_id' => $data['from_warehouse_id'],
            'to_warehouse_id' => $data['to_warehouse_id'],
            'quantity' => $data['quantity'],
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'Transferencia aplicada');
    }

    public function shipToFull(Request $request, Variant $variant, ShipStockToFull $ship): RedirectResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);
        abort_unless((int) $variant->workspace_id === (int) $workspaceId, 404);

        $data = $request->validate([
            'connection_id' => ['required', 'integer'],
            'from_warehouse_id' => ['nullable', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'external_inbound_id' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $ship->execute((int) $workspaceId, [
            'variant_id' => $variant->id,
            'connection_id' => $data['connection_id'],
            'from_warehouse_id' => $data['from_warehouse_id'] ?? null,
            'quantity' => $data['quantity'],
            'external_inbound_id' => $data['external_inbound_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'Envío a Full registrado');
    }

    public function returnFromFull(Request $request, Variant $variant, ReturnStockFromFull $returnFromFull): RedirectResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);
        abort_unless((int) $variant->workspace_id === (int) $workspaceId, 404);

        $data = $request->validate([
            'warehouse_id' => ['nullable', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'full_stock_operation_id' => ['nullable', 'integer'],
            'marketplace_inbound_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $returnFromFull->execute((int) $workspaceId, [
            'variant_id' => $variant->id,
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'quantity' => $data['quantity'],
            'full_stock_operation_id' => $data['full_stock_operation_id'] ?? null,
            'marketplace_inbound_id' => $data['marketplace_inbound_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'Devolución de Full registrada');
    }

    public function release(Reservation $reservation, ReleaseStock $release): RedirectResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);
        abort_unless((int) $reservation->workspace_id === (int) $workspaceId, 404);

        $release->execute($reservation);

        return back()->with('success', 'Reserva liberada');
    }
}
