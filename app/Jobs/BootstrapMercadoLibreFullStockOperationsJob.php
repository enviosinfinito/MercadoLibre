<?php

namespace App\Jobs;

use App\Domain\Integrations\Actions\PersistSyncCursor;
use App\Domain\Inventory\Actions\SyncMercadoLibreFullStockOperations;
use App\Integrations\Support\SyncHttpLogContext;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\Connection;
use Illuminate\Support\Facades\Log;

final class BootstrapMercadoLibreFullStockOperationsJob extends TenantAwareJob
{
    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly int $lookbackDays = 90,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('normal-sync');
    }

    protected function handleForTenant(): void
    {
        $connection = Connection::query()->findOrFail($this->connectionId);

        SyncHttpLogContext::bind(
            workspaceId: $this->workspaceId,
            connectionId: $this->connectionId,
            provider: 'mercadolibre',
        );

        try {
            $lookback = max(1, min(365, $this->lookbackDays));
            $stats = app(SyncMercadoLibreFullStockOperations::class)->execute(
                $connection,
                lookbackDays: $lookback,
            );

            app(PersistSyncCursor::class)->execute(
                $connection,
                'full_stock_operations',
                [
                    'date_from' => $stats['date_from'],
                    'date_to' => $stats['date_to'],
                    'lookback_days' => $lookback,
                ],
                [
                    'fetched' => $stats['fetched'],
                    'upserted' => $stats['upserted'],
                    'by_type' => $stats['by_type'],
                    'unmatched_count' => count($stats['unmatched_inventory_ids']),
                    'errors' => $stats['errors'],
                ],
                success: $stats['errors'] === 0,
            );

            Log::info('ml.full_operations.bootstrap_done', [
                'connection_id' => $connection->id,
                'stats' => $stats,
            ]);
        } finally {
            SyncHttpLogContext::clear();
        }
    }
}
