<?php

namespace App\Jobs;

use App\Domain\Ads\Actions\AttributeAdvertisingToOrders;
use App\Domain\Ads\Actions\SyncMercadoLibreProductAds;
use App\Domain\Integrations\Actions\ResolveSyncProfile;
use App\Integrations\Support\SyncHttpLogContext;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\Connection;
use App\Models\SyncRun;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BootstrapMercadoLibreAdsJob extends TenantAwareJob
{
    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly ?string $dateFrom = null,
        public readonly ?string $dateTo = null,
        public readonly ?int $syncRunId = null,
        public readonly bool $attribute = true,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('normal-sync');
    }

    protected function handleForTenant(): void
    {
        $connection = Connection::query()->findOrFail($this->connectionId);
        $resolve = app(ResolveSyncProfile::class);
        $profile = $resolve->execute($connection, 'ads');

        if (! $profile->enabled) {
            Log::info('ml.ads.bootstrap.skipped_disabled', [
                'connection_id' => $connection->id,
            ]);

            return;
        }

        $syncRun = $this->resolveSyncRun();

        SyncHttpLogContext::bind(
            workspaceId: $this->workspaceId,
            connectionId: $this->connectionId,
            syncRunId: (int) $syncRun->id,
            provider: 'mercadolibre',
        );

        try {
            $result = app(SyncMercadoLibreProductAds::class)->execute($connection, [
                'date_from' => $this->dateFrom,
                'date_to' => $this->dateTo,
                'dry_run' => false,
            ]);

            $attr = ['orders_touched' => 0, 'events_written' => 0];
            if ($this->attribute) {
                $attr = app(AttributeAdvertisingToOrders::class)->execute($this->workspaceId, [
                    'date_from' => $result['date_from'],
                    'date_to' => $result['date_to'],
                    'connection_id' => $this->connectionId,
                    'refresh_profit' => true,
                ]);
            }

            $syncRun->stats = array_merge(is_array($syncRun->stats) ? $syncRun->stats : [], [
                'advertisers' => $result['advertisers'],
                'campaigns' => $result['campaigns'],
                'spend_rows' => $result['spend_rows'],
                'spend' => $result['spend'],
                'orders_touched' => $attr['orders_touched'],
                'events_written' => $attr['events_written'],
            ]);
            $syncRun->status = 'completed';
            $syncRun->error_redacted = null;
            $syncRun->finished_at = now();
            $syncRun->save();
        } catch (Throwable $e) {
            $syncRun->status = 'failed';
            $syncRun->finished_at = now();
            $syncRun->error_redacted = mb_substr($e->getMessage(), 0, 800);
            $syncRun->stats = array_merge(is_array($syncRun->stats) ? $syncRun->stats : [], [
                'error_code' => str_contains($e->getMessage(), 'PA_UNAUTHORIZED')
                    ? 'PA_UNAUTHORIZED_RESULT_FROM_POLICIES'
                    : 'ads_sync_failed',
            ]);
            $syncRun->save();
            // Do not rethrow: leave a failed SyncRun the UI can explain.
            Log::warning('ml.ads.bootstrap.failed', [
                'connection_id' => $this->connectionId,
                'error' => $e->getMessage(),
            ]);
        } finally {
            SyncHttpLogContext::clear();
        }
    }

    private function resolveSyncRun(): SyncRun
    {
        if ($this->syncRunId) {
            return SyncRun::query()->findOrFail($this->syncRunId);
        }

        return SyncRun::query()->create([
            'workspace_id' => $this->workspaceId,
            'connection_id' => $this->connectionId,
            'resource_type' => 'ads',
            'mode' => 'bootstrap',
            'status' => 'running',
            'started_at' => now(),
            'stats' => [],
        ]);
    }
}
