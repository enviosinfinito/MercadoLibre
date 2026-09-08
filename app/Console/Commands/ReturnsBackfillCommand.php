<?php

namespace App\Console\Commands;

use App\Jobs\Returns\BackfillReturnCasesJob;
use App\Models\Workspace;
use Illuminate\Console\Command;

class ReturnsBackfillCommand extends Command
{
    protected $signature = 'returns:backfill {--workspace=} {--connection=}';

    protected $description = 'Backfill return cases from post-sale reversed orders/claims';

    public function handle(): int
    {
        $workspaceId = $this->option('workspace');
        $connectionId = (int) ($this->option('connection') ?: 0);

        $workspaces = $workspaceId
            ? Workspace::query()->whereKey((int) $workspaceId)->get()
            : Workspace::query()->orderBy('id')->get();

        foreach ($workspaces as $workspace) {
            $this->info("Dispatching returns backfill for workspace {$workspace->id}");
            BackfillReturnCasesJob::dispatch((int) $workspace->id, $connectionId);
        }

        return self::SUCCESS;
    }
}
