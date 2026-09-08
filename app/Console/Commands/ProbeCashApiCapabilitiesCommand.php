<?php

namespace App\Console\Commands;

use App\Domain\Cash\Actions\ProbeCashApiCapabilities;
use App\Jobs\ProbeCashApiCapabilitiesJob;
use App\Models\Connection;
use Illuminate\Console\Command;

class ProbeCashApiCapabilitiesCommand extends Command
{
    protected $signature = 'finance:probe-cash-apis
        {--connection= : Connection id}
        {--sync : Run inline instead of queue}';

    protected $description = 'Probe ML/MP cash APIs (collections, settlement/release reports, billing) per connection';

    public function handle(ProbeCashApiCapabilities $probe): int
    {
        $query = Connection::query()
            ->where('provider', 'mercadolibre')
            ->where('status', 'active')
            ->orderBy('id');

        if ($this->option('connection')) {
            $query->where('id', (int) $this->option('connection'));
        }

        $connections = $query->get();
        if ($connections->isEmpty()) {
            $this->warn('No active Mercado Libre connections found.');

            return self::SUCCESS;
        }

        foreach ($connections as $connection) {
            $this->info("Probing connection #{$connection->id} ({$connection->display_name})…");
            if ($this->option('sync')) {
                $results = $probe->execute($connection);
                foreach ($results as $key => $result) {
                    $flag = $result['enabled'] ? 'OK' : 'NO';
                    $this->line("  [{$flag}] {$key}: {$result['note']}");
                }
            } else {
                ProbeCashApiCapabilitiesJob::dispatch(
                    (int) $connection->workspace_id,
                    (int) $connection->id,
                );
                $this->line('  queued ProbeCashApiCapabilitiesJob');
            }
        }

        return self::SUCCESS;
    }
}
