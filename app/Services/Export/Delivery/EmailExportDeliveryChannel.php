<?php

namespace App\Services\Export\Delivery;

use App\Models\ExportRun;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class EmailExportDeliveryChannel implements ExportDeliveryChannel
{
    public function type(): string
    {
        return 'email';
    }

    public function send(ExportRun $run, array $channelConfig): void
    {
        $email = trim((string) ($channelConfig['value'] ?? ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid email delivery channel.');
        }

        if ($run->status !== 'completed' || ! $run->storage_path) {
            throw new RuntimeException('Export is not ready for delivery.');
        }

        $disk = $run->storage_disk ?: (string) config('export.disk', 'local');
        $contents = Storage::disk($disk)->get($run->storage_path);
        if ($contents === null) {
            throw new RuntimeException('Export file missing for email delivery.');
        }

        $filename = basename($run->storage_path);
        $module = $run->target_module ?: $run->report_type;

        Mail::raw(
            "Your scheduled export ({$module}) is ready. Token: {$run->token}",
            function ($message) use ($email, $filename, $contents, $module) {
                $message->to($email)
                    ->subject('Export ready: '.$module)
                    ->attachData($contents, $filename, [
                        'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ]);
            }
        );
    }
}
