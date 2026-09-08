<?php

namespace App\Console\Commands;

use App\Services\Export\ExportPruneService;
use Illuminate\Console\Command;

class PruneExportFilesCommand extends Command
{
    protected $signature = 'export:prune-files';

    protected $description = 'Prune expired export files and old history rows';

    public function handle(ExportPruneService $prune): int
    {
        $result = $prune->prune();
        $this->info("Files pruned: {$result['files_pruned']}; history pruned: {$result['history_pruned']}");

        return self::SUCCESS;
    }
}
