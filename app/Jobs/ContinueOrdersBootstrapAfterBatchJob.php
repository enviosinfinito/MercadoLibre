<?php

namespace App\Jobs;

use App\Domain\Ads\Actions\AttributeAdvertisingToOrders;
use App\Domain\Integrations\Actions\PersistSyncCursor;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\Connection;
use App\Models\SyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Continues orders bootstrap after a fetch batch completes: next page or final ads reattr.
 */
final class ContinueOrdersBootstrapAfterBatchJob extends TenantAwareJob
{
    /**
     * @param  array<string, mixed>|null  $nextCursor
     */
    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly int $syncRunId,
        public readonly ?array $nextCursor,
        public readonly bool $done,
        public readonly bool $hitHardCap,
        public readonly bool $reattributeAds,
        public readonly ?string $dateFrom,
        public readonly ?string $dateTo,
        public readonly int $lookbackDays,
        public readonly int $page,
        public readonly int $pagesPerBatch,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('normal-sync');
    }

    protected function handleForTenant(): void
    {
        $syncRun = SyncRun::query()->find($this->syncRunId);
        if ($syncRun === null) {
            return;
        }

        $connection = Connection::query()->find($this->connectionId);
        if ($connection === null) {
            return;
        }

        $shouldContinue = ! $this->done
            && is_array($this->nextCursor)
            && ! $this->hitHardCap;

        if ($shouldContinue) {
            $nextPage = $this->page < $this->pagesPerBatch
                ? $this->page + 1
                : 1;

            if ($this->page >= $this->pagesPerBatch) {
                $prev = is_array($syncRun->stats) ? $syncRun->stats : [];
                $totalItems = (int) ($prev['items'] ?? 0);
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

            BootstrapMercadoLibreOrdersJob::dispatch(
                $this->workspaceId,
                $this->connectionId,
                $this->nextCursor,
                $nextPage,
                (int) $syncRun->id,
                null,
                $this->dateFrom,
                $this->dateTo,
                $this->reattributeAds,
            );

            return;
        }

        if ($this->hitHardCap) {
            $prev = is_array($syncRun->stats) ? $syncRun->stats : [];
            $syncRun->stats = array_merge($prev, [
                'truncated' => true,
                'truncated_reason' => 'max_items_hard_cap_100000',
            ]);
            Log::warning('ml.orders.bootstrap.truncated_hard_cap', [
                'sync_run_id' => $syncRun->id,
                'items' => (int) ($prev['items'] ?? 0),
            ]);
        }

        $syncRun->status = 'completed';
        $syncRun->finished_at = now();
        $syncRun->save();

        $connection->forceFill([
            'freshness_status' => $this->hitHardCap ? 'syncing' : 'fresh',
        ])->save();

        app(PersistSyncCursor::class)->execute(
            $connection,
            'orders',
            is_array($this->nextCursor) ? $this->nextCursor : null,
            ['page' => $this->page, 'done' => true, 'sync_run_id' => $syncRun->id],
            success: true,
        );

        if (! $this->reattributeAds) {
            return;
        }

        $from = $this->dateFrom
            ? Carbon::parse($this->dateFrom)->toDateString()
            : now()->subDays(max(1, $this->lookbackDays) - 1)->toDateString();
        $to = $this->dateTo
            ? Carbon::parse($this->dateTo)->toDateString()
            : now()->toDateString();
        if (is_string($this->dateFrom) && str_contains($this->dateFrom, 'T')) {
            $from = Carbon::parse($this->dateFrom)->toDateString();
        }
        if (is_string($this->dateTo) && str_contains($this->dateTo, 'T')) {
            $to = Carbon::parse($this->dateTo)->subSecond()->toDateString();
        }

        $attr = app(AttributeAdvertisingToOrders::class)->execute($this->workspaceId, [
            'date_from' => $from,
            'date_to' => $to,
            'connection_id' => $this->connectionId,
            'refresh_profit' => true,
        ]);

        $syncRun->stats = array_merge(is_array($syncRun->stats) ? $syncRun->stats : [], [
            'ads_reattributed' => true,
            'ads_events_written' => $attr['events_written'] ?? 0,
            'ads_residual_amount' => $attr['residual_amount'] ?? 0,
            'ads_date_from' => $from,
            'ads_date_to' => $to,
        ]);
        $syncRun->save();
    }
}
