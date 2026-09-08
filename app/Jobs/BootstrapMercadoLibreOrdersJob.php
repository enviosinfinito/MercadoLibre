<?php

namespace App\Jobs;

use App\Domain\Integrations\Actions\PersistSyncCursor;
use App\Domain\Integrations\Actions\ResolveSyncProfile;
use App\Integrations\Contracts\ConnectorRegistry;
use App\Integrations\Contracts\Dto\PullRequest;
use App\Integrations\Support\SyncHttpLogContext;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\EncryptedCredential;
use App\Models\SyncRun;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BootstrapMercadoLibreOrdersJob extends TenantAwareJob
{
    /** Pages per chain batch; at the cap we continue with a fresh job (page reset) instead of stopping. */
    private const PAGES_PER_BATCH = 100;

    /** Absolute safety stop across the whole sync run (not per batch). */
    private const MAX_ITEMS_HARD_CAP = 100_000;

    /** @var array<string, mixed>|null */
    private ?array $lastPullNextCursor = null;

    private bool $lastPullDone = true;

    /**
     * @param  list<string>|null  $externalOrderIds  When set, skip search and fetch these order IDs (same pipeline as mass sync).
     */
    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly ?array $cursor = null,
        public readonly int $page = 1,
        public readonly ?int $syncRunId = null,
        public readonly ?array $externalOrderIds = null,
        public readonly ?string $dateFrom = null,
        public readonly ?string $dateTo = null,
        public readonly bool $reattributeAds = false,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('normal-sync');
    }

    protected function handleForTenant(): void
    {
        $connection = \App\Models\Connection::query()->findOrFail($this->connectionId);
        $resolve = app(ResolveSyncProfile::class);
        $profile = $resolve->execute($connection, 'orders');

        if (! $profile->enabled) {
            Log::info('ml.orders.bootstrap.skipped_disabled', [
                'connection_id' => $connection->id,
            ]);

            return;
        }

        $include = is_array($profile->config['include'] ?? null)
            ? $profile->config['include']
            : [];

        $lookbackDays = (int) ($profile->config['lookback_days'] ?? 90);

        $syncRun = $this->resolveSyncRun();

        SyncHttpLogContext::bind(
            workspaceId: $this->workspaceId,
            connectionId: $this->connectionId,
            syncRunId: (int) $syncRun->id,
            provider: 'mercadolibre',
        );

        try {
            $externalIds = $this->resolveExternalOrderIds(
                $connection,
                $include,
                $lookbackDays,
            );

            $filtered = $this->externalOrderIds !== null;
            $done = $filtered || (bool) ($this->lastPullDone ?? true);
            $nextCursor = $filtered ? null : ($this->lastPullNextCursor ?? null);

            $fetchJobs = [];
            foreach ($externalIds as $externalId) {
                // When reattr is requested, project synchronously so the batch finally
                // runs after orders exist (not just after HTTP fetches are queued).
                $fetchJobs[] = new FetchExternalResourceJob(
                    $this->workspaceId,
                    $this->connectionId,
                    'order',
                    $externalId,
                    projectSynchronously: $this->reattributeAds,
                );
            }
            $dispatched = count($fetchJobs);

            $prevStats = is_array($syncRun->stats) ? $syncRun->stats : [];
            $stats = [
                'pages' => (int) ($prevStats['pages'] ?? 0) + 1,
                'items' => (int) ($prevStats['items'] ?? 0) + count($externalIds),
                'dispatched' => (int) ($prevStats['dispatched'] ?? 0) + $dispatched,
                'next_cursor' => $nextCursor,
            ];

            $syncRun->stats = $stats;
            $syncRun->status = 'running';
            $syncRun->finished_at = null;
            $syncRun->save();

            app(PersistSyncCursor::class)->execute(
                $connection,
                'orders',
                is_array($nextCursor) ? $nextCursor : null,
                ['page' => $this->page, 'done' => $done, 'sync_run_id' => $syncRun->id],
                success: true,
            );

            $connection->forceFill([
                'last_synced_at' => now(),
                'freshness_status' => 'syncing',
                'last_error_redacted' => null,
            ])->save();

            Log::info('ml.orders.bootstrap.page', [
                'sync_run_id' => $syncRun->id,
                'items' => count($externalIds),
                'dispatched' => $dispatched,
                'next_cursor' => $nextCursor,
                'reattribute_ads' => $this->reattributeAds,
            ]);

            $totalItems = (int) ($stats['items'] ?? 0);
            $hitHardCap = ! $done && is_array($nextCursor) && $totalItems >= self::MAX_ITEMS_HARD_CAP;

            if ($this->reattributeAds) {
                $continuation = new ContinueOrdersBootstrapAfterBatchJob(
                    $this->workspaceId,
                    $this->connectionId,
                    (int) $syncRun->id,
                    is_array($nextCursor) ? $nextCursor : null,
                    $done,
                    $hitHardCap,
                    true,
                    $this->dateFrom,
                    $this->dateTo,
                    $lookbackDays,
                    $this->page,
                    self::PAGES_PER_BATCH,
                );

                if ($fetchJobs === []) {
                    Bus::dispatch($continuation);
                } else {
                    Bus::batch($fetchJobs)
                        ->name('ml-orders-bootstrap-'.$syncRun->id.'-p'.$this->page)
                        ->allowFailures()
                        ->finally(function () use ($continuation) {
                            Bus::dispatch($continuation);
                        })
                        ->onQueue('critical-sync')
                        ->dispatch();
                }

                return;
            }

            foreach ($fetchJobs as $job) {
                Bus::dispatch($job);
            }

            if (! $done && is_array($nextCursor) && ! $hitHardCap) {
                $nextPage = $this->page < self::PAGES_PER_BATCH
                    ? $this->page + 1
                    : 1;

                if ($this->page >= self::PAGES_PER_BATCH) {
                    $prev = is_array($syncRun->stats) ? $syncRun->stats : [];
                    $syncRun->stats = array_merge($prev, [
                        'batches_continued' => (int) ($prev['batches_continued'] ?? 0) + 1,
                        'last_batch_at_items' => $totalItems,
                    ]);
                    $syncRun->save();
                    Log::info('ml.orders.bootstrap.continue_next_batch', [
                        'sync_run_id' => $syncRun->id,
                        'items_so_far' => $totalItems,
                        'batches_continued' => (int) (($syncRun->stats['batches_continued'] ?? 0)),
                    ]);
                }

                self::dispatch(
                    $this->workspaceId,
                    $this->connectionId,
                    $nextCursor,
                    $nextPage,
                    (int) $syncRun->id,
                    null,
                    $this->dateFrom,
                    $this->dateTo,
                    false,
                );
            } else {
                if ($hitHardCap) {
                    $prev = is_array($syncRun->stats) ? $syncRun->stats : [];
                    $syncRun->stats = array_merge($prev, [
                        'truncated' => true,
                        'truncated_reason' => 'max_items_hard_cap_'.self::MAX_ITEMS_HARD_CAP,
                    ]);
                    Log::warning('ml.orders.bootstrap.truncated_hard_cap', [
                        'sync_run_id' => $syncRun->id,
                        'items' => $totalItems,
                    ]);
                }

                $syncRun->status = 'completed';
                $syncRun->finished_at = now();
                $syncRun->save();

                $connection->forceFill([
                    'freshness_status' => $hitHardCap ? 'syncing' : 'fresh',
                ])->save();
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

    /**
     * @param  array<string, mixed>  $include
     * @return list<string>
     */
    private function resolveExternalOrderIds(
        \App\Models\Connection $connection,
        array $include,
        int $lookbackDays,
    ): array {
        if ($this->externalOrderIds !== null) {
            $ids = [];
            foreach ($this->externalOrderIds as $id) {
                $externalId = trim((string) $id);
                if ($externalId !== '') {
                    $ids[] = $externalId;
                }
            }

            return array_values(array_unique($ids));
        }

        /** @var ConnectorRegistry $registry */
        $registry = app(ConnectorRegistry::class);
        $connector = $registry->get('mercadolibre');

        $accessToken = $this->resolveAccessToken($connection);
        $userId = $this->resolveUserId($connection);

        if ($accessToken === null || $userId === null) {
            throw new \RuntimeException('Mercado Libre connection is missing access_token or user_id.');
        }

        $result = $connector->pull(new PullRequest(
            resource: 'orders',
            cursor: $this->cursor ?? [],
            options: array_filter([
                'access_token' => $accessToken,
                'user_id' => $userId,
                'lookback_days' => $lookbackDays,
                'include' => $include,
                'date_from' => $this->dateFrom,
                'date_to' => $this->dateTo,
            ], fn ($v) => $v !== null && $v !== ''),
        ));

        $this->lastPullNextCursor = is_array($result->nextCursor) ? $result->nextCursor : null;
        $this->lastPullDone = (bool) ($result->nextCursor['done'] ?? ($result->nextCursor === null));

        $ids = [];
        foreach ($result->items as $orderPayload) {
            if (! is_array($orderPayload)) {
                continue;
            }

            $externalId = (string) ($orderPayload['id'] ?? '');
            if ($externalId === '') {
                continue;
            }

            $ids[] = $externalId;
        }

        return array_values(array_unique($ids));
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
            'resource_type' => 'orders',
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
