<?php

namespace App\Http\Controllers;

use App\Domain\Shared\Support\TenantContext;
use App\Models\Alert;
use App\Models\Connection;
use App\Models\DeadLetter;
use App\Models\OutboundCommand;
use App\Models\SyncRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MonitoringController extends Controller
{
    public function index(): Response
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $connections = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->orderBy('provider')
            ->get();

        $failedOutboundToday = OutboundCommand::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'failed')
            ->whereDate('created_at', today())
            ->count();

        $openAlerts = Alert::query()
            ->where('workspace_id', $workspaceId)
            ->whereNull('acknowledged_at')
            ->where('status', '!=', 'resolved')
            ->orderByDesc('triggered_at')
            ->limit(50)
            ->get();

        $openDeadLetters = DeadLetter::query()
            ->where('workspace_id', $workspaceId)
            ->whereNull('resolved_at')
            ->orderByDesc('failed_at')
            ->limit(25)
            ->get();

        $recentSyncs = SyncRun::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('started_at')
            ->limit(15)
            ->get();

        $recentOutbound = OutboundCommand::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'connection_id', 'command_type', 'status', 'dry_run', 'last_error_redacted', 'created_at', 'processed_at']);

        $syncWindow = SyncRun::query()
            ->where('workspace_id', $workspaceId)
            ->where('started_at', '>=', now()->subDays(30))
            ->get(['status']);

        $syncTotal = $syncWindow->count();
        $syncOk = $syncWindow->whereIn('status', ['completed', 'success'])->count();
        $sla = $syncTotal > 0 ? round(($syncOk / $syncTotal) * 100, 1) : 100.0;

        $integrationRows = $connections->map(function (Connection $connection) use ($workspaceId) {
            $failed = OutboundCommand::query()
                ->where('workspace_id', $workspaceId)
                ->where('connection_id', $connection->id)
                ->where('status', 'failed')
                ->where('created_at', '>=', now()->subDay())
                ->count();

            $lastSync = SyncRun::query()
                ->where('workspace_id', $workspaceId)
                ->where('connection_id', $connection->id)
                ->orderByDesc('started_at')
                ->first(['started_at', 'finished_at', 'status']);

            $health = 'Operativo';
            if ($connection->needs_reauthorization || $connection->status !== 'active') {
                $health = 'Error';
            } elseif ($failed >= 5 || $connection->freshness_status === 'error') {
                $health = 'Degradado';
            } elseif ($failed > 0 || $connection->freshness_status === 'stale') {
                $health = 'Latencia';
            }

            return [
                'id' => $connection->id,
                'provider' => $connection->provider,
                'external_user_id' => $connection->external_user_id,
                'status' => $connection->status,
                'health' => $health,
                'incidents_24h' => $failed,
                'last_synced_at' => $connection->last_synced_at,
                'last_sync_run_at' => $lastSync?->started_at,
                'freshness_status' => $connection->freshness_status,
            ];
        });

        $affected = $integrationRows->whereIn('health', ['Degradado', 'Latencia', 'Error'])->count();

        return Inertia::render('Monitoring/Index', [
            'generated_at' => now()->toIso8601String(),
            'kpis' => [
                'resources_affected' => $affected,
                'incidents_today' => $failedOutboundToday + $openAlerts->count(),
                'sla_30d' => $sla,
                'open_alerts' => $openAlerts->count(),
                'open_dead_letters' => $openDeadLetters->count(),
            ],
            'integrations' => $integrationRows,
            'alerts' => $openAlerts,
            'dead_letters' => $openDeadLetters,
            'recent_syncs' => $recentSyncs,
            'recent_outbound' => $recentOutbound,
        ]);
    }

    public function acknowledge(Request $request, Alert $alert): RedirectResponse
    {
        abort_unless((int) $alert->workspace_id === TenantContext::id(), 404);

        $alert->acknowledged_at = now();
        $alert->status = 'acknowledged';
        $alert->save();

        return redirect()->back()->with('success', 'Alerta reconocida.');
    }
}
