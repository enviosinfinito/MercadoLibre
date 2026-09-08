<?php

namespace App\Jobs;

use App\Domain\Catalog\Support\ListingFreshness;
use App\Domain\Integrations\Actions\PersistSyncCursor;
use App\Domain\Integrations\Actions\ResolveSyncProfile;
use App\Integrations\Contracts\ConnectorRegistry;
use App\Integrations\Contracts\Dto\PullRequest;
use App\Integrations\Support\SyncHttpLogContext;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\EncryptedCredential;
use App\Models\RawResourceSnapshot;
use App\Models\SyncRun;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BootstrapMercadoLibreClaimsJob extends TenantAwareJob
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
        $profile = $resolve->execute($connection, 'claims');

        if (! $profile->enabled) {
            Log::info('ml.claims.bootstrap.skipped_disabled', [
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

            $accessToken = $this->resolveAccessToken($connection);
            $userId = $this->resolveUserId($connection);

            if ($accessToken === null || $userId === null) {
                throw new \RuntimeException('Mercado Libre connection is missing access_token or user_id.');
            }

            $result = $connector->pull(new PullRequest(
                resource: 'claims',
                cursor: $this->cursor ?? [],
                options: [
                    'access_token' => $accessToken,
                    'user_id' => $userId,
                    'include' => $include,
                ],
            ));

            $storeSnapshot = ($include['raw_snapshot'] ?? true) === true;
            $dispatched = 0;

            foreach ($result->items as $claimPayload) {
                if (! is_array($claimPayload)) {
                    continue;
                }

                $externalId = (string) ($claimPayload['id'] ?? '');
                if ($externalId === '') {
                    continue;
                }

                if (! $storeSnapshot) {
                    continue;
                }

                $checksum = ListingFreshness::contentChecksum($claimPayload);
                $snapshot = RawResourceSnapshot::query()->create([
                    'workspace_id' => $this->workspaceId,
                    'connection_id' => $this->connectionId,
                    'resource_type' => 'claim',
                    'external_id' => $externalId,
                    'payload' => $claimPayload,
                    'checksum' => $checksum,
                    'fetched_at' => now(),
                ]);

                ProcessCanonicalClaimJob::dispatch(
                    $this->workspaceId,
                    $this->connectionId,
                    (int) $snapshot->id,
                );
                $dispatched++;
            }

            $done = (bool) ($result->nextCursor['done'] ?? ($result->nextCursor === null));

            $prevStats = is_array($syncRun->stats) ? $syncRun->stats : [];
            $stats = [
                'pages' => (int) ($prevStats['pages'] ?? 0) + 1,
                'items' => (int) ($prevStats['items'] ?? 0) + count($result->items),
                'dispatched' => (int) ($prevStats['dispatched'] ?? 0) + $dispatched,
                'next_cursor' => $result->nextCursor,
            ];

            $syncRun->stats = $stats;
            $syncRun->status = $done ? 'completed' : 'running';
            $syncRun->finished_at = $done ? now() : null;
            $syncRun->save();

            app(PersistSyncCursor::class)->execute(
                $connection,
                'claims',
                $result->nextCursor,
                ['page' => $this->page, 'done' => $done, 'sync_run_id' => $syncRun->id],
                success: true,
            );

            $connection->forceFill([
                'last_synced_at' => now(),
                'freshness_status' => $done ? 'fresh' : 'syncing',
                'last_error_redacted' => null,
            ])->save();

            Log::info('ml.claims.bootstrap.page', [
                'sync_run_id' => $syncRun->id,
                'items' => count($result->items),
                'dispatched' => $dispatched,
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
            'resource_type' => 'claims',
            'mode' => 'bootstrap',
            'status' => 'running',
            'started_at' => now(),
            'stats' => [
                'pages' => 0,
                'items' => 0,
                'dispatched' => 0,
            ],
        ]);
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
