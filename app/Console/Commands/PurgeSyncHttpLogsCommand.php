<?php

namespace App\Console\Commands;

use App\Models\SyncHttpLog;
use Illuminate\Console\Command;

class PurgeSyncHttpLogsCommand extends Command
{
    protected $signature = 'sync-http-logs:purge
                            {--force : Skip confirmation}';

    protected $description = 'Delete all rows from sync_http_logs (diagnostic HTTP audit trail)';

    public function handle(): int
    {
        $count = SyncHttpLog::query()->count();

        if ($count === 0) {
            $this->info('sync_http_logs is already empty.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Delete {$count} sync_http_logs rows?")) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $deleted = 0;

        do {
            $batch = SyncHttpLog::query()->orderBy('id')->limit(1000)->pluck('id');
            if ($batch->isEmpty()) {
                break;
            }
            $deleted += SyncHttpLog::query()->whereIn('id', $batch)->delete();
        } while ($batch->isNotEmpty());

        $this->info("Purged {$deleted} sync_http_logs rows.");

        return self::SUCCESS;
    }
}
