<?php

namespace App\Console\Commands;

use App\Services\Export\ScheduledReportRunnerService;
use Illuminate\Console\Command;

class RunScheduledReportsCommand extends Command
{
    protected $signature = 'reports:run-scheduled';

    protected $description = 'Run due scheduled export reports';

    public function handle(ScheduledReportRunnerService $runner): int
    {
        $count = $runner->runDue();
        $this->info("Dispatched {$count} scheduled export(s).");

        return self::SUCCESS;
    }
}
