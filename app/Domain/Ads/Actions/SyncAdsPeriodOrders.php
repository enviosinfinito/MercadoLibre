<?php

namespace App\Domain\Ads\Actions;

use App\Jobs\BootstrapMercadoLibreOrdersJob;
use App\Models\Connection;
use App\Models\SyncRun;
use Illuminate\Support\Carbon;

/**
 * Sync Mercado Libre orders for an ads lookback window, then re-attribute ads spend.
 */
final class SyncAdsPeriodOrders
{
    /**
     * @return array{sync_run_id: int, status: string, date_from: string, date_to: string}
     */
    public function execute(
        int $workspaceId,
        int $connectionId,
        string $dateFrom,
        string $dateTo,
    ): array {
        $connection = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->where('id', $connectionId)
            ->firstOrFail();

        $from = Carbon::parse($dateFrom)->startOfDay();
        $to = Carbon::parse($dateTo)->endOfDay();

        // ML /orders/search expects UTC ISO; end is exclusive (start of next day).
        $dateFromIso = $from->copy()->utc()->format('Y-m-d\TH:i:s.000\Z');
        $dateToIso = $to->copy()->utc()->addDay()->startOfDay()->format('Y-m-d\TH:i:s.000\Z');

        $syncRun = SyncRun::query()->create([
            'workspace_id' => $workspaceId,
            'connection_id' => $connection->id,
            'resource_type' => 'orders',
            'mode' => 'ads_coverage',
            'status' => 'running',
            'started_at' => now(),
            'stats' => [
                'pages' => 0,
                'items' => 0,
                'dispatched' => 0,
                'purpose' => 'ads_coverage',
                'ml_item_period' => [
                    'date_from' => $from->toDateString(),
                    'date_to' => $to->toDateString(),
                ],
            ],
        ]);

        BootstrapMercadoLibreOrdersJob::dispatch(
            $workspaceId,
            (int) $connection->id,
            null,
            1,
            (int) $syncRun->id,
            null,
            $dateFromIso,
            $dateToIso,
            true,
        );

        return [
            'sync_run_id' => (int) $syncRun->id,
            'status' => 'running',
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
        ];
    }
}
