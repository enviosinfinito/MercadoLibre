<?php

namespace App\Jobs;

use App\Domain\Catalog\Actions\UpsertChannelListing;
use App\Domain\Catalog\Support\ListingFreshness;
use App\Domain\Integrations\Actions\EnsureFreshConnectionToken;
use App\Domain\Integrations\Actions\ResolveSyncProfile;
use App\Integrations\Contracts\ConnectorRegistry;
use App\Integrations\Contracts\Dto\FetchRequest;
use App\Integrations\Support\SyncHttpLogContext;
use App\Integrations\Sync\SyncResourceCatalog;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\ChannelListing;
use App\Models\Connection;
use App\Models\RawResourceSnapshot;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

final class FetchExternalResourceJob extends TenantAwareJob
{
    /**
     * @param  array<string, bool>|null  $includeOverrides  Merge sobre include del sync profile (p. ej. forzar pictures).
     */
    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly string $resourceType,
        public readonly string $externalId,
        public readonly bool $projectSynchronously = false,
        public readonly ?array $includeOverrides = null,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('critical-sync');
    }

    protected function handleForTenant(): void
    {
        $connection = Connection::query()->findOrFail($this->connectionId);
        $catalog = app(SyncResourceCatalog::class);
        $resolve = app(ResolveSyncProfile::class);
        $resourceKey = $catalog->resolveResourceKey($this->resourceType);
        $profile = $resolve->execute($connection, $resourceKey);

        if (! $profile->enabled) {
            Log::info('fetch.external_resource.skipped_disabled', [
                'connection_id' => $connection->id,
                'resource_key' => $resourceKey,
                'external_id' => $this->externalId,
            ]);

            return;
        }

        SyncHttpLogContext::bind(
            workspaceId: $this->workspaceId,
            connectionId: $this->connectionId,
            provider: $connection->provider,
        );

        try {
            $include = is_array($profile->config['include'] ?? null)
                ? $profile->config['include']
                : [];
            if (is_array($this->includeOverrides) && $this->includeOverrides !== []) {
                $include = array_merge($include, $this->includeOverrides);
            }
            $this->fetchAndProject($connection, $include);
        } finally {
            SyncHttpLogContext::clear();
        }
    }

    /**
     * @param  array<string, mixed>  $include
     */
    private function fetchAndProject(Connection $connection, array $include): void
    {
        $ensureToken = app(EnsureFreshConnectionToken::class);
        $accessToken = $ensureToken->execute($connection);

        /** @var ConnectorRegistry $registry */
        $registry = app(ConnectorRegistry::class);
        $connector = $registry->get($connection->provider);

        try {
            $result = $connector->fetch(new FetchRequest(
                resource: $this->resourceType,
                externalId: $this->externalId,
                options: ['access_token' => $accessToken],
            ));
        } catch (Throwable $e) {
            if (! $this->looksLikeAuthFailure($e)) {
                throw $e;
            }

            $accessToken = $ensureToken->execute(
                $connection->fresh(['credential']) ?? $connection,
                force: true,
            );
            $result = $connector->fetch(new FetchRequest(
                resource: $this->resourceType,
                externalId: $this->externalId,
                options: ['access_token' => $accessToken],
            ));
        }

        $payload = $result->payload;
        $snapshot = null;
        $checksum = ListingFreshness::contentChecksum($payload);
        $payload['content_checksum'] = $checksum;

        if (($include['raw_snapshot'] ?? true) === true) {
            $snapshot = RawResourceSnapshot::query()->create([
                'workspace_id' => $this->workspaceId,
                'connection_id' => $this->connectionId,
                'resource_type' => $this->resourceType,
                'external_id' => $this->externalId,
                'payload' => $payload,
                'checksum' => $checksum,
                'fetched_at' => now(),
            ]);

            Log::info('fetch.external_resource.stored', [
                'snapshot_id' => $snapshot->id,
                'resource_type' => $this->resourceType,
                'external_id' => $this->externalId,
            ]);
        }

        if (in_array($this->resourceType, ['order', 'orders'], true) && $snapshot !== null) {
            $this->dispatchProjection(
                new ProcessCanonicalOrderJob(
                    $this->workspaceId,
                    $this->connectionId,
                    $snapshot->id,
                    applyInventoryEffects: true,
                    projectSynchronously: $this->projectSynchronously,
                ),
            );
        }

        if (in_array($this->resourceType, ['shipment', 'shipments'], true) && $snapshot !== null) {
            $this->dispatchProjection(
                new ProcessCanonicalShipmentJob(
                    $this->workspaceId,
                    $this->connectionId,
                    $snapshot->id,
                ),
            );
        }

        if (in_array($this->resourceType, ['question', 'questions'], true) && $snapshot !== null) {
            $this->dispatchProjection(
                new ProcessCanonicalQuestionJob(
                    $this->workspaceId,
                    $this->connectionId,
                    $snapshot->id,
                ),
            );
        }

        if (in_array($this->resourceType, ['claim', 'claims'], true) && $snapshot !== null) {
            $this->dispatchProjection(
                new ProcessCanonicalClaimJob(
                    $this->workspaceId,
                    $this->connectionId,
                    $snapshot->id,
                ),
            );
        }

        if (in_array($this->resourceType, ['payment', 'payments'], true) && $snapshot !== null) {
            $this->dispatchProjection(
                new ProcessCanonicalPaymentJob(
                    $this->workspaceId,
                    $this->connectionId,
                    $snapshot->id,
                ),
            );
        }

        if (in_array($this->resourceType, ['item', 'items', 'listing'], true)
            && $connection->provider === 'mercadolibre'
        ) {
            if ($snapshot !== null) {
                $payload['raw_snapshot_id'] = $snapshot->id;
            }

            $existing = ChannelListing::query()
                ->where('connection_id', $connection->id)
                ->where('external_item_id', $this->externalId)
                ->first();

            if (! empty($include['description']) && $accessToken !== '') {
                if (ListingFreshness::shouldFetchDescription($existing, $payload, $checksum)) {
                    try {
                        $description = $connector->fetch(new FetchRequest(
                            resource: 'item_description',
                            externalId: $this->externalId,
                            options: ['access_token' => $accessToken],
                        ));
                        $payload['description'] = $description->payload === []
                            ? null
                            : $description->payload;
                    } catch (Throwable $e) {
                        Log::warning('fetch.item.description_failed', [
                            'external_id' => $this->externalId,
                            'error' => mb_substr($e->getMessage(), 0, 200),
                        ]);
                    }
                } elseif ($existing !== null && filled($existing->description)) {
                    $payload['description'] = $existing->description;
                }
            }

            app(UpsertChannelListing::class)->execute($connection, $payload, $include);
        }
    }

    private function looksLikeAuthFailure(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, '401')
            || str_contains($message, 'unauthorized')
            || str_contains($message, 'invalid access token');
    }

    private function dispatchProjection(object $job): void
    {
        if ($this->projectSynchronously) {
            Bus::dispatchSync($job);

            return;
        }

        Bus::dispatch($job);
    }
}
