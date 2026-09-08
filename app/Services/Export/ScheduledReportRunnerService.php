<?php

namespace App\Services\Export;

use App\Models\ScheduledExport;
use App\Models\User;
use Illuminate\Support\Carbon;

final class ScheduledReportRunnerService
{
    public function __construct(
        private readonly ExportReportOrchestrator $orchestrator,
    ) {}

    public function runDue(): int
    {
        $count = 0;
        ScheduledExport::query()
            ->due()
            ->orderBy('id')
            ->each(function (ScheduledExport $schedule) use (&$count) {
                $this->runOne($schedule);
                $count++;
            });

        return $count;
    }

    public function runOne(ScheduledExport $schedule): void
    {
        $user = User::query()->find($schedule->created_by);
        if (! $user) {
            return;
        }

        $module = (string) ($schedule->target_module ?: $schedule->report_type);
        $filters = $this->resolveRelativeFilters($schedule->filters ?? []);

        $this->orchestrator->start(
            user: $user,
            workspaceId: (int) $schedule->workspace_id,
            targetModule: $module,
            selectionMode: 'filter',
            filters: $filters,
            exportConfigId: $schedule->user_export_preference_id,
            exportPresetId: $schedule->export_preset_id,
            scheduledExportId: $schedule->id,
            query: $schedule->query,
            source: 'scheduled',
        );

        $schedule->forceFill([
            'last_run_at' => now(),
            'next_run_at' => $schedule->computeNextRunAt(now()->addMinute()),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function resolveRelativeFilters(array $filters): array
    {
        $period = (string) ($filters['period_type'] ?? '');
        $tz = config('app.timezone');
        $now = Carbon::now($tz);

        return match ($period) {
            'this_week' => array_merge($filters, [
                'from' => $now->copy()->startOfWeek()->toDateString(),
                'to' => $now->copy()->endOfWeek()->toDateString(),
            ]),
            'this_month' => array_merge($filters, [
                'from' => $now->copy()->startOfMonth()->toDateString(),
                'to' => $now->copy()->endOfMonth()->toDateString(),
            ]),
            'last_x_days' => array_merge($filters, [
                'from' => $now->copy()->subDays(max(1, (int) ($filters['last_x_days_value'] ?? 7)))->toDateString(),
                'to' => $now->toDateString(),
            ]),
            default => $filters,
        };
    }
}
