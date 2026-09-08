<?php

namespace App\Jobs;

use App\Models\ExportRun;
use App\Models\ScheduledExport;
use App\Services\Export\Delivery\ExportDeliveryManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SendScheduledExportDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $exportRunId)
    {
        $this->onQueue((string) config('export.queue', 'exports'));
    }

    public function handle(ExportDeliveryManager $delivery): void
    {
        $run = ExportRun::query()->withoutGlobalScopes()->findOrFail($this->exportRunId);
        if ($run->status !== 'completed' || ! $run->scheduled_export_id) {
            return;
        }

        $schedule = ScheduledExport::query()->withoutGlobalScopes()->find($run->scheduled_export_id);
        if (! $schedule) {
            return;
        }

        $channels = $schedule->delivery_channels
            ?? collect($schedule->delivery['emails'] ?? [])->map(fn ($email) => [
                'type' => 'email',
                'value' => $email,
            ])->all();

        if ($channels === []) {
            return;
        }

        $delivery->deliver($run, $channels);
    }
}
