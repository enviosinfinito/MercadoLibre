<?php

namespace App\Jobs;

use App\Domain\Catalog\Actions\UpsertChannelListing;
use App\Domain\Catalog\Support\ListingFreshness;
use App\Domain\Integrations\Actions\PersistSyncCursor;
use App\Domain\Integrations\Actions\ResolveSyncProfile;
use App\Integrations\Contracts\ConnectorRegistry;
use App\Integrations\Contracts\Dto\FetchRequest;
use App\Integrations\Contracts\Dto\PullRequest;
use App\Integrations\Support\SyncHttpLogContext;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\ChannelListing;
use App\Models\EncryptedCredential;
use App\Models\RawResourceSnapshot;
use App\Models\SyncRun;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BootstrapMercadoLibreListingsJob extends TenantAwareJob
{
    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly ?array $cursor = null,
        public readonly int $page = 1,
        public readonly ?int $syncRunId = null,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('normal-sync');
    }

    protected function handleForTenant(): void
    {
        $connection = \App\Models\Connection::query()->findOrFail($this->connectionId);
        $resolve = app(ResolveSyncProfile::class);
        $profile = $resolve->execute($connection, 'listings');

        if (! $profile->enabled) {
            Log::info('ml.listings.bootstrap.skipped_disabled', [
                'connection_id' => $connection->id,
            ]);

            return;
        }

        $include = is_array($profile->config['include'] ?? null)
            ? $profile->config['include']
            : [];

        $syncRun = $this->resolveSyncRun();

        SyncHttpLogContext::bind(
            workspaceId: $this->workspaceId,
            connectionId: $this->connectionId,
            syncRunId: (int) $syncRun->id,
            provider: 'mercadolibre',
        );

        try {
            /** @var ConnectorRegistry $registry */
            $registry = app(ConnectorRegistry::class);
            $connector = $registry->get('mercadolibre');
            $upsert = app(UpsertChannelListing::class);

            $accessToken = $this->resolveAccessToken($connection);
            $userId = $this->resolveUserId($connection);

            if ($accessToken === null || $userId === null) {
                throw new \RuntimeException('Mercado Libre connection is missing access_token or user_id.');
            }

            $result = $connector->pull(new PullRequest(
                resource: 'listings',
                cursor: $this->cursor ?? [],
                options: [
                    'access_token' => $accessToken,
                    'user_id' => $userId,
                    'statuses' => $profile->config['statuses'] ?? ['active'],
                    'include' => $include,
                ],
            ));

            $existingByExternalId = ChannelListing::query()
                ->where('connection_id', $connection->id)
                ->whereIn(
                    'external_item_id',
                    collect($result->items)
                        ->map(fn ($item) => is_array($item) ? (string) ($item['id'] ?? '') : '')
                        ->filter()
                        ->all(),
                )
                ->get()
                ->keyBy('external_item_id');

            $pendingDescriptions = [];
            $prepared = [];

            foreach ($result->items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $externalId = (string) ($item['id'] ?? '');
                if ($externalId === '') {
                    continue;
                }

                $checksum = ListingFreshness::contentChecksum($item);
                $item['content_checksum'] = $checksum;
                /** @var ChannelListing|null $existing */
                $existing = $existingByExternalId->get($externalId);

                if (! empty($include['raw_snapshot'])) {
                    $snapshot = RawResourceSnapshot::query()->create([
                        'workspace_id' => $this->workspaceId,
                        'connection_id' => $this->connectionId,
                        'resource_type' => 'item',
                        'external_id' => $externalId,
                        'payload' => $item,
                        'checksum' => $checksum,
                        'fetched_at' => now(),
                    ]);
                    $item['raw_snapshot_id'] = $snapshot->id;
                }

                $needsDescription = ! empty($include['description'])
                    && ListingFreshness::shouldFetchDescription($existing, $item, $checksum);

                if ($needsDescription) {
                    $pendingDescriptions[$externalId] = true;
                } elseif ($existing !== null && filled($existing->description)) {
                    // Keep local description without re-fetching.
                    $item['description'] = $existing->description;
                }

                $prepared[$externalId] = $item;
            }

            if ($pendingDescriptions !== []) {
                $fetched = $this->fetchDescriptions(
                    $connector,
                    $accessToken,
                    array_keys($pendingDescriptions),
                );
                foreach ($fetched as $externalId => $description) {
                    if (isset($prepared[$externalId])) {
                        $prepared[$externalId]['description'] = $description;
                    }
                }
            }

            $upserted = 0;
            $descriptionsSkipped = ! empty($include['description'])
                ? max(0, count($prepared) - count($pendingDescriptions))
                : 0;
            foreach ($prepared as $item) {
                $upsert->execute($connection, $item, $include);
                $upserted++;
            }

            $done = (bool) ($result->nextCursor['done'] ?? ($result->nextCursor === null));

            $prevStats = is_array($syncRun->stats) ? $syncRun->stats : [];
            $stats = [
                'pages' => (int) ($prevStats['pages'] ?? 0) + 1,
                'items' => (int) ($prevStats['items'] ?? 0) + count($result->items),
                'upserted' => (int) ($prevStats['upserted'] ?? 0) + $upserted,
                'descriptions_fetched' => (int) ($prevStats['descriptions_fetched'] ?? 0) + count($pendingDescriptions),
                'descriptions_skipped' => (int) ($prevStats['descriptions_skipped'] ?? 0) + $descriptionsSkipped,
                'next_cursor' => $result->nextCursor,
            ];

            $syncRun->stats = $stats;
            $syncRun->status = $done ? 'completed' : 'running';
            $syncRun->finished_at = $done ? now() : null;
            $syncRun->save();

            app(PersistSyncCursor::class)->execute(
                $connection,
                'listings',
                $result->nextCursor,
                ['page' => $this->page, 'done' => $done, 'sync_run_id' => $syncRun->id],
                success: true,
            );

            $connection->forceFill([
                'last_synced_at' => now(),
                'freshness_status' => $done ? 'fresh' : 'syncing',
                'last_error_redacted' => null,
            ])->save();

            Log::info('ml.listings.bootstrap.page', [
                'sync_run_id' => $syncRun->id,
                'items' => count($result->items),
                'upserted' => $upserted,
                'descriptions_fetched' => count($pendingDescriptions),
                'descriptions_skipped' => $descriptionsSkipped,
                'next_cursor' => $result->nextCursor,
            ]);

            if (! $done && is_array($result->nextCursor) && $this->page < 100) {
                self::dispatch(
                    $this->workspaceId,
                    $this->connectionId,
                    $result->nextCursor,
                    $this->page + 1,
                    (int) $syncRun->id,
                );
            }
        } catch (Throwable $e) {
            $syncRun->status = 'failed';
            $syncRun->error_redacted = mb_substr($e->getMessage(), 0, 500);
            $syncRun->finished_at = now();
            $syncRun->save();

            $connection->forceFill([
                'freshness_status' => 'error',
                'last_error_redacted' => mb_substr($e->getMessage(), 0, 500),
            ])->save();

            throw $e;
        } finally {
            SyncHttpLogContext::clear();
        }
    }

    private function resolveSyncRun(): SyncRun
    {
        if ($this->syncRunId !== null) {
            $existing = SyncRun::query()->find($this->syncRunId);
            if ($existing !== null) {
                return $existing;
            }
        }

        return SyncRun::query()->create([
            'workspace_id' => $this->workspaceId,
            'connection_id' => $this->connectionId,
            'resource_type' => 'listings',
            'mode' => 'bootstrap',
            'status' => 'running',
            'started_at' => now(),
            'stats' => [
                'pages' => 0,
                'items' => 0,
                'upserted' => 0,
                'descriptions_fetched' => 0,
                'descriptions_skipped' => 0,
            ],
        ]);
    }

    /**
     * @param  list<string>  $itemIds
     * @return array<string, array<string, mixed>|string|null>
     */
    private function fetchDescriptions(object $connector, string $accessToken, array $itemIds): array
    {
        $out = [];

        // Sequential with soft-404 handling in connector; keeps LoggedHttpClient + context intact.
        // Concurrency via Http::pool would bypass the logged client.
        foreach ($itemIds as $itemId) {
            if ($itemId === '') {
                continue;
            }

            try {
                $result = $connector->fetch(new FetchRequest(
                    resource: 'item_description',
                    externalId: $itemId,
                    options: ['access_token' => $accessToken],
                ));
                $payload = $result->payload;
                if ($payload === []) {
                    $out[$itemId] = null;

                    continue;
                }
                $out[$itemId] = $payload;
            } catch (Throwable $e) {
                Log::warning('ml.listings.description.fetch_failed', [
                    'item_id' => $itemId,
                    'error' => mb_substr($e->getMessage(), 0, 200),
                ]);
                $out[$itemId] = null;
            }
        }

        return $out;
    }

    private function resolveAccessToken(\App\Models\Connection $connection): ?string
    {
        try {
            return app(\App\Domain\Integrations\Actions\EnsureFreshConnectionToken::class)
                ->execute($connection);
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveUserId(\App\Models\Connection $connection): ?string
    {
        if (is_string($connection->external_user_id) && $connection->external_user_id !== '') {
            return $connection->external_user_id;
        }

        $plain = $this->plainCredential($connection);
        $userId = $plain['user_id'] ?? null;

        return is_string($userId) || is_int($userId) ? (string) $userId : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function plainCredential(\App\Models\Connection $connection): array
    {
        /** @var EncryptedCredential|null $credential */
        $credential = $connection->credential;
        if ($credential === null) {
            return [];
        }

        try {
            $plain = $credential->plainPayload();

            return is_array($plain) ? $plain : [];
        } catch (Throwable) {
            return [];
        }
    }
}
