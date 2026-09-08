<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable([
    'workspace_id',
    'saved_report_view_id',
    'analytics_report_id',
    'user_export_preference_id',
    'export_preset_id',
    'created_by',
    'name',
    'report_type',
    'target_module',
    'format',
    'schedule_type',
    'schedule_config',
    'cron_expression',
    'timezone',
    'is_active',
    'delivery',
    'delivery_channels',
    'query',
    'filters',
    'last_run_at',
    'next_run_at',
])]
class ScheduledExport extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'delivery' => 'array',
            'delivery_channels' => 'array',
            'query' => 'array',
            'filters' => 'array',
            'schedule_config' => 'array',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function analyticsReport(): BelongsTo
    {
        return $this->belongsTo(AnalyticsReport::class);
    }

    public function userExportPreference(): BelongsTo
    {
        return $this->belongsTo(UserExportPreference::class);
    }

    public function exportPreset(): BelongsTo
    {
        return $this->belongsTo(ExportPreset::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->active()
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now());
    }

    public function computeNextRunAt(?Carbon $from = null): Carbon
    {
        $from = ($from ?? now())->copy()->timezone($this->timezone ?: config('app.timezone'));
        $config = $this->schedule_config ?? [];
        $time = (string) ($config['time'] ?? '08:00');
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');
        $candidate = $from->copy()->setTime((int) $hour, (int) $minute, 0);

        return match ($this->schedule_type) {
            'weekly' => $this->nextWeekly($candidate, (int) ($config['day_of_week'] ?? 1)),
            'monthly' => $this->nextMonthly($candidate, (int) ($config['day_of_month'] ?? 1)),
            default => $candidate->gt($from) ? $candidate : $candidate->addDay(),
        };
    }

    private function nextWeekly(Carbon $candidate, int $dayOfWeek): Carbon
    {
        // Carbon: 0=Sunday … ISO often 1=Monday — accept 0-6
        while ((int) $candidate->dayOfWeek !== $dayOfWeek || $candidate->lte(now()->timezone($candidate->timezone))) {
            $candidate->addDay();
        }

        return $candidate;
    }

    private function nextMonthly(Carbon $candidate, int $dayOfMonth): Carbon
    {
        $dayOfMonth = max(1, min(28, $dayOfMonth));
        $candidate->day($dayOfMonth);
        if ($candidate->lte(now()->timezone($candidate->timezone))) {
            $candidate->addMonthNoOverflow()->day($dayOfMonth);
        }

        return $candidate;
    }
}
