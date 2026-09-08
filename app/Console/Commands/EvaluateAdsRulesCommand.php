<?php

namespace App\Console\Commands;

use App\Domain\Ads\Services\AdsActionExecutor;
use App\Domain\Ads\Services\AdsRulesEvaluator;
use App\Models\AdActionProposal;
use App\Models\AdSpendDaily;
use App\Models\AdWorkspaceSetting;
use Illuminate\Console\Command;

class EvaluateAdsRulesCommand extends Command
{
    protected $signature = 'ads:evaluate-rules
                            {--workspace= : Workspace ID (default: all with ads data or settings)}
                            {--execute-approved : Also execute approved proposals}';

    protected $description = 'Evaluate Product Ads assistant rules and create action proposals';

    public function handle(AdsRulesEvaluator $evaluator, AdsActionExecutor $executor): int
    {
        $workspaceOpt = $this->option('workspace');
        $executeApproved = (bool) $this->option('execute-approved');

        if ($workspaceOpt) {
            $workspaceIds = [(int) $workspaceOpt];
        } else {
            $workspaceIds = AdWorkspaceSetting::query()->pluck('workspace_id')
                ->merge(AdSpendDaily::query()->distinct()->pluck('workspace_id'))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        foreach ($workspaceIds as $workspaceId) {
            $result = $evaluator->execute((int) $workspaceId);
            $this->info("Workspace {$workspaceId}: created={$result['proposals_created']} skipped={$result['proposals_skipped']} auto={$result['auto_queued']} mode={$result['mode']}");

            if (! $executeApproved && $result['auto_queued'] === 0) {
                continue;
            }

            $settings = AdWorkspaceSetting::forWorkspace((int) $workspaceId);
            if ($settings->autopilot_mode !== 'auto' && ! $executeApproved) {
                continue;
            }

            $proposals = AdActionProposal::query()
                ->where('workspace_id', $workspaceId)
                ->where('status', 'approved')
                ->orderBy('id')
                ->limit(50)
                ->get();

            foreach ($proposals as $proposal) {
                $out = $executor->executeProposal($proposal);
                $this->line("  proposal #{$proposal->id}: {$out['status']} — {$out['message']}");
            }
        }

        return self::SUCCESS;
    }
}
