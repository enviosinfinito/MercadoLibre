<?php

namespace App\Services\Export;

use App\Models\ExportRun;
use Illuminate\Support\Facades\Storage;

final class ExportPruneService
{
    /**
     * @return array{files_pruned: int, history_pruned: int}
     */
    public function prune(): array
    {
        $fileDays = (int) config('export.file_retention_days', 14);
        $historyDays = (int) config('export.history_retention_days', 90);
        $filesPruned = 0;
        $historyPruned = 0;

        ExportRun::query()
            ->whereNotNull('storage_path')
            ->where(function ($q) use ($fileDays) {
                $q->where('file_expired_at', '<=', now())
                    ->orWhere(function ($q2) use ($fileDays) {
                        $q2->whereNull('file_expired_at')
                            ->where('finished_at', '<=', now()->subDays($fileDays));
                    });
            })
            ->orderBy('id')
            ->each(function (ExportRun $run) use (&$filesPruned) {
                $disk = $run->storage_disk ?: (string) config('export.disk', 'local');
                if ($run->storage_path && Storage::disk($disk)->exists($run->storage_path)) {
                    Storage::disk($disk)->delete($run->storage_path);
                    $filesPruned++;
                }
                $run->forceFill([
                    'storage_path' => null,
                    'download_link' => null,
                    'file_size' => null,
                ])->save();
            });

        $historyPruned = ExportRun::query()
            ->where('created_at', '<=', now()->subDays($historyDays))
            ->delete();

        return [
            'files_pruned' => $filesPruned,
            'history_pruned' => $historyPruned,
        ];
    }
}
