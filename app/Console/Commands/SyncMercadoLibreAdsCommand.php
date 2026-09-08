<?php

namespace App\Console\Commands;

use App\Jobs\BootstrapMercadoLibreAdsJob;
use App\Models\Connection;
use Illuminate\Console\Command;

class SyncMercadoLibreAdsCommand extends Command
{
    protected $signature = 'ads:sync-mercadolibre
        {--connection= : Connection id}
        {--workspace= : Workspace id}
        {--days=30 : Lookback days}
        {--sync : Run synchronously}';

    protected $description = 'Enqueue Product Ads sync (+ P&L attribution) for Mercado Libre connections';

    public function handle(): int
    {
        $days = max(1, min(90, (int) $this->option('days')));
        $dateTo = now()->toDateString();
        $dateFrom = now()->subDays($days - 1)->toDateString();

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
        $query->orderBy('id')->each(function (Connection $connection) use (&$count, $dateFrom, $dateTo) {
            $job = new BootstrapMercadoLibreAdsJob(
                (int) $connection->workspace_id,
                (int) $connection->id,
                $dateFrom,
                $dateTo,
            );

            if ($this->option('sync')) {
                dispatch_sync($job);
            } else {
                dispatch($job);
            }
            $count++;
        });

        $this->info("Queued ads sync for {$count} connection(s) ({$dateFrom} → {$dateTo}).");

        return self::SUCCESS;
    }
}
