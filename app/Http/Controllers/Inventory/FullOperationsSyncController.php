<?php

namespace App\Http\Controllers\Inventory;

use App\Jobs\BootstrapMercadoLibreFullStockOperationsJob;
use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FullOperationsSyncController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $data = $request->validate([
            'connection_id' => ['nullable', 'integer'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $days = (int) ($data['days'] ?? 90);

        $query = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider', 'mercadolibre')
            ->whereIn('status', ['active', 'connected']);

        if (isset($data['connection_id'])) {
            $query->whereKey((int) $data['connection_id']);
        }

        $queued = 0;
        $query->orderBy('id')->each(function (Connection $connection) use (&$queued, $days): void {
            BootstrapMercadoLibreFullStockOperationsJob::dispatch(
                (int) $connection->workspace_id,
                (int) $connection->id,
                $days,
            );
            $queued++;
        });

        return redirect()
            ->back()
            ->with(
                'success',
                $queued > 0
                    ? "Sincronización Full encolada para {$queued} conexión(es) (últimos {$days} días)."
                    : 'No hay conexiones Mercado Libre activas para sincronizar.',
            );
    }
}
