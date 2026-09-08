<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class PruneTelescopeBySizeCommand extends Command
{
    protected $signature = 'telescope:prune-by-size
                            {--max-mb= : Override max size in MB (default: config telescope.max_storage_mb)}
                            {--chunk=1000 : Entries to delete per batch}';

    protected $description = 'Prune oldest Telescope entries when storage exceeds the configured max size';

    /** @var list<string> */
    private array $tables = [
        'telescope_entries',
        'telescope_entries_tags',
        'telescope_monitoring',
    ];

    public function handle(): int
    {
        $connection = config('telescope.storage.database.connection');
        $driver = DB::connection($connection)->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->warn("telescope:prune-by-size only supports MySQL/MariaDB (got {$driver}).");

            return self::SUCCESS;
        }

        $maxMb = (float) ($this->option('max-mb') ?: config('telescope.max_storage_mb', 500));
        $chunk = max(100, (int) $this->option('chunk'));
        $maxBytes = (int) round($maxMb * 1024 * 1024);
        // Leave headroom so we don't thrash right at the limit.
        $targetBytes = (int) round($maxBytes * 0.9);

        $this->refreshTableStats($connection);

        $sizeBytes = $this->telescopeSizeBytes($connection);
        $sizeMb = round($sizeBytes / 1024 / 1024, 2);

        if ($sizeBytes <= $maxBytes) {
            $this->info("Telescope OK: {$sizeMb} MB (limit {$maxMb} MB). Nothing to prune.");

            return self::SUCCESS;
        }

        $this->warn("Telescope at {$sizeMb} MB exceeds {$maxMb} MB. Pruning oldest entries...");

        $bytesPerEntry = max(1, $this->averageBytesPerEntry($connection, $sizeBytes));
        $entries = (int) DB::connection($connection)->table('telescope_entries')->count();
        $entriesToKeep = (int) floor($targetBytes / $bytesPerEntry);
        $entriesToDelete = max(0, $entries - $entriesToKeep);

        if ($entriesToDelete === 0) {
            $this->optimizeTables($connection);
            $this->refreshTableStats($connection);
            $finalMb = round($this->telescopeSizeBytes($connection) / 1024 / 1024, 2);
            $this->info("No rows pruned. After optimize: {$finalMb} MB (limit {$maxMb} MB).");

            return self::SUCCESS;
        }

        $deleted = 0;

        while ($deleted < $entriesToDelete) {
            $take = min($chunk, $entriesToDelete - $deleted);

            $uuids = DB::connection($connection)
                ->table('telescope_entries')
                ->orderBy('sequence')
                ->limit($take)
                ->pluck('uuid');

            if ($uuids->isEmpty()) {
                break;
            }

            DB::connection($connection)
                ->table('telescope_entries_tags')
                ->whereIn('entry_uuid', $uuids)
                ->delete();

            $batchDeleted = DB::connection($connection)
                ->table('telescope_entries')
                ->whereIn('uuid', $uuids)
                ->delete();

            $deleted += $batchDeleted;

            if ($batchDeleted === 0) {
                break;
            }
        }

        $this->optimizeTables($connection);
        $this->refreshTableStats($connection);

        $finalMb = round($this->telescopeSizeBytes($connection) / 1024 / 1024, 2);
        $this->info("Pruned {$deleted} entries. Telescope now at {$finalMb} MB (limit {$maxMb} MB).");

        return self::SUCCESS;
    }

    private function telescopeSizeBytes(?string $connection): int
    {
        $database = DB::connection($connection)->getDatabaseName();
        $names = array_map(
            fn (string $table) => "{$database}/{$table}",
            $this->tables,
        );

        try {
            $row = DB::connection($connection)
                ->table('information_schema.innodb_tablespaces')
                ->selectRaw('COALESCE(SUM(file_size), 0) as bytes')
                ->whereIn('name', $names)
                ->first();

            $bytes = (int) ($row->bytes ?? 0);

            if ($bytes > 0) {
                return $bytes;
            }
        } catch (Throwable) {
            // Fall back below.
        }

        $row = DB::connection($connection)
            ->table('information_schema.tables')
            ->selectRaw('COALESCE(SUM(data_length + index_length), 0) as bytes')
            ->where('table_schema', $database)
            ->whereIn('table_name', $this->tables)
            ->first();

        return (int) ($row->bytes ?? 0);
    }

    private function averageBytesPerEntry(?string $connection, int $sizeBytes): int
    {
        $entries = (int) DB::connection($connection)->table('telescope_entries')->count();

        if ($entries === 0) {
            return 1;
        }

        return (int) max(1, (int) ceil($sizeBytes / $entries));
    }

    private function refreshTableStats(?string $connection): void
    {
        $qualified = collect($this->tables)
            ->map(fn (string $table) => '`'.str_replace('`', '``', $table).'`')
            ->implode(', ');

        try {
            DB::connection($connection)->statement("ANALYZE TABLE {$qualified}");
        } catch (Throwable) {
            // Best-effort; size probe still has INNODB_TABLESPACES fallback.
        }
    }

    private function optimizeTables(?string $connection): void
    {
        $this->info('Optimizing Telescope tables to reclaim disk...');

        foreach ($this->tables as $table) {
            try {
                DB::connection($connection)->statement("OPTIMIZE TABLE `{$table}`");
            } catch (Throwable $e) {
                $this->warn("Could not optimize {$table}: {$e->getMessage()}");
            }
        }
    }
}
