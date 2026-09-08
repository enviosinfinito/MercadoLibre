<?php

namespace App\Console\Commands;

use App\Domain\Automation\Actions\EvaluateRuleTemplates;
use App\Models\Workspace;
use Illuminate\Console\Command;

class AutomationRunCommand extends Command
{
    protected $signature = 'automation:run
                            {--workspace= : Limit evaluation to a single workspace ID}';

    protected $description = 'Evaluate built-in automation rule templates and create alerts';

    public function handle(EvaluateRuleTemplates $evaluate): int
    {
        $query = Workspace::query()->orderBy('id');

        if ($this->option('workspace')) {
            $query->whereKey((int) $this->option('workspace'));
        }

        $total = 0;

        $query->each(function (Workspace $workspace) use ($evaluate, &$total) {
            $created = $evaluate->execute((int) $workspace->id);
            $count = $created->count();
            $total += $count;

            if ($count > 0) {
                $this->line("Workspace {$workspace->id}: created {$count} alert(s)");
            }
        });

        $this->info("Automation run complete. Alerts created: {$total}");

        return self::SUCCESS;
    }
}
