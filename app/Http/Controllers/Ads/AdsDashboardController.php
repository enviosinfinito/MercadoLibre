<?php

namespace App\Http\Controllers\Ads;

use App\Domain\Ads\Services\AdsMetricsQuery;
use App\Domain\Integrations\Actions\ResolveSyncProfile;
use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\SyncRun;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdsDashboardController extends Controller
{
    public function __construct(
        private readonly AdsMetricsQuery $adsMetricsQuery,
        private readonly ResolveSyncProfile $resolveSyncProfile,
    ) {}

    public function index(Request $request): Response
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $filters = [
            'period' => $request->input('period', 'last_30_days'),
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'connection_ids' => $this->arrayInput($request, 'connection_ids'),
            'campaign_ids' => $this->arrayInput($request, 'campaign_ids'),
            'advertiser_ids' => $this->arrayInput($request, 'advertiser_ids'),
            'item_ids' => $this->arrayInput($request, 'item_ids', asString: true),
            'product_ids' => $this->arrayInput($request, 'product_ids'),
            'group_by' => $request->input('group_by', 'day'),
        ];

        $metrics = $this->adsMetricsQuery->execute((int) $workspaceId, $filters);

        $connections = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider', 'mercadolibre')
            ->orderBy('display_name')
            ->get(['id', 'display_name', 'external_user_id', 'color', 'provider', 'site_id', 'status']);

        $adsEnabledCount = 0;
        foreach ($connections as $connection) {
            if ($this->resolveSyncProfile->isEnabled($connection, 'ads')) {
                $adsEnabledCount++;
            }
        }

        $lastSync = SyncRun::query()
            ->where('workspace_id', $workspaceId)
            ->where('resource_type', 'ads')
            ->orderByDesc('id')
            ->first(['id', 'connection_id', 'status', 'error_redacted', 'stats', 'finished_at', 'created_at']);

        $syncStatus = [
            'ads_enabled_connections' => $adsEnabledCount,
            'connections_total' => $connections->count(),
            'last_run' => $lastSync ? [
                'id' => $lastSync->id,
                'connection_id' => $lastSync->connection_id,
                'status' => $lastSync->status,
                'error' => $lastSync->error_redacted,
                'stats' => $lastSync->stats,
                'finished_at' => optional($lastSync->finished_at ?? $lastSync->created_at)?->toIso8601String(),
            ] : null,
            'permission_blocked' => is_string($lastSync?->error_redacted)
                && (
                    str_contains($lastSync->error_redacted, 'PA_UNAUTHORIZED')
                    || str_contains($lastSync->error_redacted, 'Permisos funcionales')
                    || str_contains($lastSync->error_redacted, '403')
                ),
        ];

        return Inertia::render('Ads/Dashboard', [
            'metrics' => $metrics,
            'syncStatus' => $syncStatus,
            'connections' => $connections
                ->map(fn (Connection $c) => [
                    'id' => $c->id,
                    'display_name' => $c->display_name ?: $c->external_user_id,
                    'external_user_id' => $c->external_user_id,
                    'color' => $c->color,
                    'provider' => $c->provider,
                    'site_id' => $c->site_id,
                ])
                ->values()
                ->all(),
            'groupByOptions' => [
                ['value' => 'day', 'label' => 'Día'],
                ['value' => 'week', 'label' => 'Semana'],
                ['value' => 'month', 'label' => 'Mes'],
                ['value' => 'campaign', 'label' => 'Campaña'],
                ['value' => 'item', 'label' => 'Publicación'],
                ['value' => 'product', 'label' => 'Producto'],
                ['value' => 'connection', 'label' => 'Conexión'],
            ],
            'periodOptions' => [
                ['value' => 'last_7_days', 'label' => 'Últimos 7 días'],
                ['value' => 'last_30_days', 'label' => 'Últimos 30 días'],
                ['value' => 'this_month', 'label' => 'Este mes'],
                ['value' => 'previous_month', 'label' => 'Mes anterior'],
                ['value' => 'last_90_days', 'label' => 'Últimos 90 días'],
                ['value' => 'custom', 'label' => 'Personalizado'],
            ],
        ]);
    }

    /**
     * @return list<int>|list<string>
     */
    private function arrayInput(Request $request, string $key, bool $asString = false): array
    {
        $raw = $request->input($key, []);
        if (! is_array($raw)) {
            $raw = $raw !== null && $raw !== '' ? [$raw] : [];
        }

        if ($asString) {
            return array_values(array_filter(array_map(
                static fn ($v) => trim((string) $v),
                $raw,
            ), static fn ($v) => $v !== ''));
        }

        return array_values(array_filter(array_map('intval', $raw), static fn ($v) => $v > 0));
    }
}
