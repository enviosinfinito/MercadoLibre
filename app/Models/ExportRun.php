<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'user_id',
    'saved_report_view_id',
    'analytics_report_id',
    'scheduled_export_id',
    'token',
    'report_type',
    'target_module',
    'format',
    'status',
    'progress',
    'selection_mode',
    'total_rows',
    'estimated_time',
    'filters',
    'query',
    'export_params',
    'stats',
    'row_count',
    'duration_ms',
    'storage_disk',
    'storage_path',
    'download_link',
    'file_size',
    'file_expired_at',
    'error_redacted',
    'started_at',
    'finished_at',
    'last_activity_at',
])]
class ExportRun extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'query' => 'array',
            'export_params' => 'array',
            'stats' => 'array',
            'progress' => 'integer',
            'total_rows' => 'integer',
            'row_count' => 'integer',
            'file_size' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'file_expired_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function analyticsReport(): BelongsTo
    {
        return $this->belongsTo(AnalyticsReport::class);
    }

    public function scheduledExport(): BelongsTo
    {
        return $this->belongsTo(ScheduledExport::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['queued', 'pending', 'processing']);
    }

    public function touchActivity(): void
    {
        $this->forceFill(['last_activity_at' => now()])->save();
    }

    public function markProcessing(int $progress = 1): void
    {
        $this->forceFill([
            'status' => 'processing',
            'progress' => $progress,
            'started_at' => $this->started_at ?? now(),
            'last_activity_at' => now(),
        ])->save();
    }

    public function markProgress(int $progress): void
    {
        $this->forceFill([
            'progress' => min(95, max(0, $progress)),
            'last_activity_at' => now(),
        ])->save();
    }

    public function markCompleted(string $disk, string $path, int $rowCount, int $fileSize, ?string $downloadLink = null): void
    {
        $retention = (int) config('export.file_retention_days', 14);
        $this->forceFill([
            'status' => 'completed',
            'progress' => 100,
            'storage_disk' => $disk,
            'storage_path' => $path,
            'download_link' => $downloadLink,
            'row_count' => $rowCount,
            'file_size' => $fileSize,
            'finished_at' => now(),
            'last_activity_at' => now(),
            'file_expired_at' => now()->addDays($retention),
            'error_redacted' => null,
        ])->save();
    }

    public function markError(string $message): void
    {
        $this->forceFill([
            'status' => 'error',
            'error_redacted' => substr($message, 0, 500),
            'finished_at' => now(),
            'last_activity_at' => now(),
        ])->save();
    }

    public function markCancelled(): void
    {
        $this->forceFill([
            'status' => 'cancelled',
            'finished_at' => now(),
            'last_activity_at' => now(),
        ])->save();
    }

    public function isStalled(): bool
    {
        if (! in_array($this->status, ['processing', 'queued', 'pending'], true)) {
            return false;
        }
        $threshold = (int) config('export.stalled_after_seconds', 600);
        $ref = $this->last_activity_at ?? $this->started_at ?? $this->created_at;

        return $ref !== null && $ref->lt(now()->subSeconds($threshold));
    }
}
