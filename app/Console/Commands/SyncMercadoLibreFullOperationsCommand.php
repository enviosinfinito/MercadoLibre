<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Actions\SyncMercadoLibreFullStockOperations;
use App\Jobs\BootstrapMercadoLibreFullStockOperationsJob;
use App\Models\Connection;
use Illuminate\Console\Command;

class SyncMercadoLibreFullOperationsCommand extends Command
{
    protected $signature = 'meli:sync-full-operations
        {--connection= : Connection id}
        {--workspace= : Workspace id}
        {--days=15 : Lookback days (chunked to max 60 per request)}
        {--sync : Run synchronously and print type breakdown}';

    protected $description = 'Sync Mercado Envíos Full stock operations (entradas/salidas) for ML connections';

    public function handle(SyncMercadoLibreFullStockOperations $sync): int
    {
        $days = max(1, min(365, (int) $this->option('days')));

        $query = Connection::query()
            ->where('provider', 'mercadolibre')
            ->whereIn('status', ['active', 'connected']);

        if ($this->option('connection')) {
            $query->where('id', (int) $this->option('connection'));
        }
        if ($this->option('workspace')) {
            $query->where('workspace_id', (int) $this->option('workspace'));
        }

        $count = 0;
        $query->orderBy('id')->each(function (Connection $connection) use (&$count, $days, $sync): void {
            if ($this->option('sync')) {
                $stats = $sync->execute($connection, lookbackDays: $days);
                $this->info(sprintf(
                    'Connection #%d: fetched=%d upserted=%d windows=%d errors=%d (%s → %s)',
                    $connection->id,
                    $stats['fetched'],
                    $stats['upserted'],
                    $stats['windows'],
                    $stats['errors'],
                    $stats['date_from'],
                    $stats['date_to'],
                ));
                if ($stats['by_type'] !== []) {
                    $this->table(['Tipo', 'Cantidad'], collect($stats['by_type'])
                        ->map(fn ($n, $type) => [$type, $n])
                        ->values()
                        ->all());
                }
                if ($stats['unmatched_inventory_ids'] !== []) {
                    $this->warn('Inventory IDs sin match a listing: '.implode(', ', array_slice($stats['unmatched_inventory_ids'], 0, 20)));
                }
            } else {
                BootstrapMercadoLibreFullStockOperationsJob::dispatch(
                    (int) $connection->workspace_id,
                    (int) $connection->id,
                    $days,
                );
                $this->line("Queued full operations sync for connection #{$connection->id}");
            }
            $count++;
        });

        $this->info("Processed {$count} connection(s) (lookback {$days} days).");

        return self::SUCCESS;
    }
}
