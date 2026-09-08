<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Actions\SyncMercadoLibreChannelInventory;
use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MarketplaceStockSyncController extends Controller
{
    public function store(Request $request, SyncMercadoLibreChannelInventory $sync): RedirectResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $data = $request->validate([
            'connection_id' => ['nullable', 'integer'],
        ]);

        $stats = $sync->execute(
            (int) $workspaceId,
            isset($data['connection_id']) ? (int) $data['connection_id'] : null,
        );

        return redirect()
            ->back()
            ->with(
                'success',
                "Stock ML: {$stats['listings']} pubs, {$stats['enriched']} enriquecidas, {$stats['locations_upserted']} ubicaciones, {$stats['errors']} errores.",
            );
    }
}
