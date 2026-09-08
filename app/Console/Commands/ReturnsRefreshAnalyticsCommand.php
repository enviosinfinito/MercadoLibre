<?php

namespace App\Console\Commands;

use App\Domain\Returns\Actions\UpdateReturnProductDailyStats;
use App\Domain\Returns\Services\ReturnAnomalyDetectionService;
use App\Domain\Returns\Services\ReturnInsightService;
use App\Domain\Returns\Support\ReturnPeriodResolver;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ReturnsRefreshAnalyticsCommand extends Command
{
    protected $signature = 'returns:refresh-analytics {--workspace=} {--days=14}';

    protected $description = 'Refresh return daily stats, anomalies and insights';

    public function handle(
        UpdateReturnProductDailyStats $stats,
        ReturnAnomalyDetectionService $anomalies,
        ReturnInsightService $insights,
        ReturnPeriodResolver $periods,
    ): int {
        $days = max(1, (int) $this->option('days'));
        $workspaceId = $this->option('workspace');
        $workspaces = $workspaceId
            ? Workspace::query()->whereKey((int) $workspaceId)->get()
            : Workspace::query()->orderBy('id')->get();

        foreach ($workspaces as $workspace) {
            for ($i = 0; $i < $days; $i++) {
                $date = now()->subDays($i);
                $stats->execute((int) $workspace->id, $date);
            }

            $period = $periods->resolve('last_30_days');
            $anomalies->detect((int) $workspace->id, $period['start'], $period['end']);
            $insights->generate(
                (int) $workspace->id,
                $period['start'],
                $period['end'],
                $period['key'],
            );
            $this->info("Refreshed returns analytics for workspace {$workspace->id}");
        }

        return self::SUCCESS;
    }
}
