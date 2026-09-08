<?php

namespace App\Jobs;

use App\Models\ExportRun;
use App\Models\ScheduledExport;
use App\Notifications\AnalyticsExportReadyNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

final class ProcessScheduledExportsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('exports');
    }

    public function handle(): void
    {
        $due = ScheduledExport::query()
            ->withoutGlobalScopes()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(50)
            ->get();

        foreach ($due as $schedule) {
            $query = $schedule->query;
            if (! $query && $schedule->analytics_report_id) {
                $report = $schedule->analyticsReport()->withoutGlobalScopes()->first();
                $query = $report?->query;
            }

            $run = ExportRun::query()->withoutGlobalScopes()->create([
                'workspace_id' => $schedule->workspace_id,
                'user_id' => $schedule->created_by,
                'analytics_report_id' => $schedule->analytics_report_id,
                'report_type' => $schedule->report_type,
                'format' => $schedule->format,
                'status' => 'pending',
                'query' => $query,
                'filters' => ['scheduled_export_id' => $schedule->id],
            ]);

            GenerateExportJob::dispatch((int) $schedule->workspace_id, (int) $run->id);

            $emails = $schedule->delivery['emails'] ?? [];
            if (is_array($emails) && $emails !== []) {
                Notification::route('mail', $emails)
                    ->notify(new AnalyticsExportReadyNotification($run->id, $schedule->name));
            }

            $schedule->last_run_at = now();
            $schedule->next_run_at = match ($schedule->cron_expression) {
                '0 * * * *' => now()->addHour()->startOfHour(),
                '0 8 * * *' => now()->addDay()->setTime(8, 0),
                '0 8 * * 1' => now()->next(\Carbon\Carbon::MONDAY)->setTime(8, 0),
                default => now()->addDay(),
            };
            $schedule->save();
        }
    }
}
