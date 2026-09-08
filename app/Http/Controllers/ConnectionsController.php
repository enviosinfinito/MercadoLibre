<?php

namespace App\Http\Controllers;

use App\Domain\Integrations\Actions\BuildConnectionPurchaseExperienceSummary;
use App\Domain\Integrations\Actions\BuildConnectionReputationTimeline;
use App\Domain\Integrations\Actions\ResolveSyncProfile;
use App\Domain\Integrations\Actions\UpsertSyncProfiles;
use App\Domain\Shared\Support\TenantContext;
use App\Integrations\Sync\SyncResourceCatalog;
use App\Jobs\BootstrapMercadoLibreAdsJob;
use App\Jobs\BootstrapMercadoLibreListingsJob;
use App\Jobs\BootstrapMercadoLibreOrdersJob;
use App\Jobs\BootstrapMercadoLibreClaimsJob;
use App\Jobs\BootstrapMercadoLibreQuestionsJob;
use App\Http\Controllers\Concerns\RespondsWithJsonPaginator;
use App\Http\Requests\Connections\UpdateConnectionColorRequest;
use App\Models\ChannelListing;
use App\Models\Connection;
use App\Models\ConnectionInvite;
use App\Support\ConnectionColorPalette;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConnectionsController extends Controller
{
    use RespondsWithJsonPaginator;

    public function index(): Response
    {
        $workspaceId = TenantContext::id();

        $connections = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('id')
            ->get(['id', 'provider', 'external_user_id', 'site_id', 'display_name', 'permalink', 'avatar_url', 'reputation_level', 'power_seller_status', 'color', 'status', 'needs_reauthorization', 'freshness_status', 'last_synced_at']);

        $invites = ConnectionInvite::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'provider', 'token', 'expires_at', 'used_at', 'revoked_at', 'created_at'])
            ->map(fn (ConnectionInvite $invite) => [
                'id' => $invite->id,
                'provider' => $invite->provider,
                'url' => $invite->url(),
                'status' => $invite->status(),
                'expires_at' => $invite->expires_at?->toIso8601String(),
                'used_at' => $invite->used_at?->toIso8601String(),
                'revoked_at' => $invite->revoked_at?->toIso8601String(),
                'created_at' => $invite->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Connections/Index', [
            'connections' => $connections,
            'invites' => $invites,
            'platforms' => $this->platforms(),
        ]);
    }

    public function show(
        Request $request,
        Connection $connection,
        SyncResourceCatalog $catalog,
        ResolveSyncProfile $resolveSyncProfile,
        BuildConnectionReputationTimeline $buildTimeline,
        BuildConnectionPurchaseExperienceSummary $buildPeSummary,
    ): Response|JsonResponse {
        abort_unless((int) $connection->workspace_id === TenantContext::id(), 404);

        $profiles = $resolveSyncProfile->allForConnection($connection);
        $listingsProfile = $profiles->firstWhere('resource_key', 'listings');
        $listingsInclude = is_array($listingsProfile?->config['include'] ?? null)
            ? $listingsProfile->config['include']
            : [];
        $peEnabled = (bool) ($listingsInclude['purchase_experience'] ?? false);

        $payload = [
            'connection' => [
                'id' => $connection->id,
                'provider' => $connection->provider,
                'external_user_id' => $connection->external_user_id,
                'site_id' => $connection->site_id,
                'display_name' => $connection->display_name,
                'permalink' => $connection->permalink,
                'avatar_url' => $connection->avatar_url,
                'reputation_level' => $connection->reputation_level,
                'power_seller_status' => $connection->power_seller_status,
                'reputation_meta' => $connection->reputation_meta,
                'reputation_synced_at' => $connection->reputation_synced_at?->toIso8601String(),
                'account_profile' => $connection->account_profile,
                'account_profile_synced_at' => $connection->account_profile_synced_at?->toIso8601String(),
                'color' => $connection->color,
                'status' => $connection->status,
                'needs_reauthorization' => $connection->needs_reauthorization,
                'freshness_status' => $connection->freshness_status,
                'last_synced_at' => $connection->last_synced_at?->toIso8601String(),
            ],
            'reputation_timeline' => $buildTimeline->execute($connection),
            'purchase_experience_summary' => $buildPeSummary->execute($connection, $peEnabled),
            'purchase_experience_listings' => ChannelListing::query()
                ->where('connection_id', $connection->id)
                ->whereIn('pe_color', ['orange', 'red', 'yellow'])
                ->orderByDesc('updated_at')
                ->limit(40)
                ->get(['id', 'title', 'external_item_id', 'status', 'pe_color', 'pe_value', 'permalink'])
                ->sortBy(static function (ChannelListing $listing) {
                    return match ($listing->pe_color) {
                        'red' => 0,
                        'orange' => 1,
                        'yellow' => 2,
                        default => 9,
                    };
                })
                ->take(20)
                ->values()
                ->map(static fn (ChannelListing $listing) => [
                    'id' => $listing->id,
                    'title' => $listing->title,
                    'external_item_id' => $listing->external_item_id,
                    'status' => $listing->status,
                    'pe_color' => $listing->pe_color,
                    'pe_value' => $listing->pe_value,
                    'permalink' => $listing->permalink,
                ])
                ->all(),
            'catalog' => $catalog->forProviderAsArray($connection->provider),
            'profiles' => $profiles->map(static fn ($profile) => [
                'resource_key' => $profile->resource_key,
                'enabled' => $profile->enabled,
                'config' => $profile->config ?? ['include' => []],
            ])->values()->all(),
            'platforms' => $this->platforms(),
        ];

        if ($this->wantsJsonWithoutInertia($request)) {
            return response()->json($payload);
        }

        return Inertia::render('Connections/Show', $payload);
    }

    public function updateSyncProfiles(
        Request $request,
        Connection $connection,
        UpsertSyncProfiles $upsertSyncProfiles,
    ): RedirectResponse {
        abort_unless((int) $connection->workspace_id === TenantContext::id(), 404);

        $data = $request->validate([
            'profiles' => ['required', 'array'],
            'profiles.*.resource_key' => ['required', 'string'],
            'profiles.*.enabled' => ['required', 'boolean'],
            'profiles.*.config' => ['nullable', 'array'],
            'profiles.*.config.include' => ['nullable', 'array'],
            'profiles.*.config.statuses' => ['nullable', 'array'],
            'profiles.*.config.modes' => ['nullable', 'array'],
            'profiles.*.config.lookback_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $upsertSyncProfiles->execute($connection, $data['profiles']);

        return redirect()
            ->route('connections.show', $connection)
            ->with('success', 'Configuración de sincronización guardada.');
    }

    public function syncNow(
        Request $request,
        Connection $connection,
        ResolveSyncProfile $resolveSyncProfile,
    ): RedirectResponse {
        abort_unless((int) $connection->workspace_id === TenantContext::id(), 404);
        abort_unless(in_array($connection->status, ['active', 'connected'], true), 422);

        $data = $request->validate([
            'resource_key' => ['nullable', 'string'],
        ]);

        $resourceKey = $data['resource_key'] ?? 'listings';

        if ($connection->provider === 'mercadolibre' && $resourceKey === 'listings') {
            abort_unless($resolveSyncProfile->isEnabled($connection, 'listings'), 422);

            BootstrapMercadoLibreListingsJob::dispatch(
                (int) $connection->workspace_id,
                (int) $connection->id,
            );

            return redirect()
                ->route('connections.show', $connection)
                ->with('success', 'Sincronización de publicaciones encolada.');
        }

        if ($connection->provider === 'mercadolibre' && $resourceKey === 'orders') {
            abort_unless($resolveSyncProfile->isEnabled($connection, 'orders'), 422);

            BootstrapMercadoLibreOrdersJob::dispatch(
                (int) $connection->workspace_id,
                (int) $connection->id,
            );

            return redirect()
                ->route('connections.show', $connection)
                ->with('success', 'Sincronización de órdenes encolada.');
        }

        if ($connection->provider === 'mercadolibre' && $resourceKey === 'questions') {
            abort_unless($resolveSyncProfile->isEnabled($connection, 'questions'), 422);

            BootstrapMercadoLibreQuestionsJob::dispatch(
                (int) $connection->workspace_id,
                (int) $connection->id,
            );

            return redirect()
                ->route('connections.show', $connection)
                ->with('success', 'Sincronización de preguntas encolada.');
        }

        if ($connection->provider === 'mercadolibre' && $resourceKey === 'claims') {
            abort_unless($resolveSyncProfile->isEnabled($connection, 'claims'), 422);

            BootstrapMercadoLibreClaimsJob::dispatch(
                (int) $connection->workspace_id,
                (int) $connection->id,
            );

            return redirect()
                ->route('connections.show', $connection)
                ->with('success', 'Sincronización de reclamos encolada.');
        }

        if ($connection->provider === 'mercadolibre' && $resourceKey === 'ads') {
            abort_unless($resolveSyncProfile->isEnabled($connection, 'ads'), 422);

            BootstrapMercadoLibreAdsJob::dispatch(
                (int) $connection->workspace_id,
                (int) $connection->id,
            );

            return redirect()
                ->route('connections.show', $connection)
                ->with('success', 'Sincronización de publicidad encolada.');
        }

        return redirect()
            ->route('connections.show', $connection)
            ->with('success', 'No hay sync bootstrap disponible para ese recurso aún.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'in:mercadolibre,amazon'],
            'external_user_id' => ['nullable', 'string'],
            'site_id' => ['nullable', 'string'],
        ]);

        $workspaceId = TenantContext::id();

        Connection::query()->create([
            'workspace_id' => $workspaceId,
            'provider' => $data['provider'],
            'external_user_id' => $data['external_user_id'] ?? null,
            'site_id' => $data['site_id'] ?? null,
            'color' => ConnectionColorPalette::nextForWorkspace($workspaceId),
            'status' => 'pending',
        ]);

        return redirect()->route('connections.index');
    }

    public function updateColor(
        UpdateConnectionColorRequest $request,
        Connection $connection,
    ): RedirectResponse|JsonResponse {
        abort_unless((int) $connection->workspace_id === TenantContext::id(), 404);

        $connection->update([
            'color' => ConnectionColorPalette::normalize($request->validated('color')),
        ]);

        if ($request->wantsJson() || $request->expectsJson()) {
            return response()->json([
                'id' => $connection->id,
                'color' => $connection->color,
            ]);
        }

        return back();
    }

    public function destroy(Connection $connection): RedirectResponse
    {
        abort_unless((int) $connection->workspace_id === TenantContext::id(), 404);
        $connection->delete();

        return redirect()->route('connections.index');
    }

    public function syncListings(Connection $connection, ResolveSyncProfile $resolveSyncProfile): RedirectResponse
    {
        abort_unless((int) $connection->workspace_id === TenantContext::id(), 404);
        abort_unless($connection->provider === 'mercadolibre', 404);
        abort_unless(in_array($connection->status, ['active', 'connected'], true), 422);
        abort_unless($resolveSyncProfile->isEnabled($connection, 'listings'), 422);

        BootstrapMercadoLibreListingsJob::dispatch(
            (int) $connection->workspace_id,
            (int) $connection->id,
        );

        return redirect()
            ->route('connections.index')
            ->with('success', 'Sincronización de publicaciones encolada. Revisa Matching en unos segundos.');
    }

    /**
     * @return list<array{id:string,name:string,group:string,status:string,setup_label:string,connect_route:?string}>
     */
    private function platforms(): array
    {
        return [
            [
                'id' => 'mercadolibre',
                'name' => 'Mercado Libre',
                'group' => 'popular',
                'status' => 'available',
                'setup_label' => 'Conexión OAuth',
                'connect_route' => 'oauth.mercadolibre.redirect',
            ],
            [
                'id' => 'amazon',
                'name' => 'Amazon',
                'group' => 'popular',
                'status' => 'available',
                'setup_label' => 'Conexión OAuth',
                'connect_route' => 'oauth.amazon.redirect',
            ],
            [
                'id' => 'ecart',
                'name' => 'Ecart',
                'group' => 'popular',
                'status' => 'coming_soon',
                'setup_label' => 'Próximamente',
                'connect_route' => null,
            ],
        ];
    }
}
