<?php

namespace App\Http\Controllers\Analytics;

use App\Domain\Analytics\Query\QueryAst;
use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AnalyticsReport;
use App\Models\ScheduledExport;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ScheduledExportController extends Controller
{
    public function index(): Response
    {
        $schedules = ScheduledExport::query()
            ->where('workspace_id', TenantContext::id())
            ->orderByDesc('id')
            ->paginate(25);

        $reports = AnalyticsReport::query()
            ->where('workspace_id', TenantContext::id())
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Analytics/ScheduledExports', [
            'schedules' => $schedules,
            'reports' => $reports,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'analytics_report_id' => ['nullable', 'integer', 'exists:analytics_reports,id'],
            'query' => ['nullable', 'array'],
            'report_type' => ['required', 'string', 'max:100'],
            'format' => ['nullable', 'string', 'in:csv,xlsx'],
            'cron_expression' => ['required', 'string', 'max:64'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'delivery' => ['nullable', 'array'],
            'delivery.emails' => ['nullable', 'array'],
            'delivery.emails.*' => ['email'],
        ]);

        if (! empty($data['query'])) {
            QueryAst::fromArray($data['query']);
        }

        $timezone = $data['timezone'] ?? 'America/Mexico_City';

        ScheduledExport::query()->create([
            'workspace_id' => TenantContext::id(),
            'saved_report_view_id' => null,
            'analytics_report_id' => $data['analytics_report_id'] ?? null,
            'created_by' => $request->user()->id,
            'name' => $data['name'],
            'report_type' => $data['report_type'],
            'format' => $data['format'] ?? 'csv',
            'cron_expression' => $data['cron_expression'],
            'timezone' => $timezone,
            'is_active' => true,
            'delivery' => $data['delivery'] ?? null,
            'query' => $data['query'] ?? null,
            'next_run_at' => $this->nextRunAt($data['cron_expression'], $timezone),
        ]);

        return back()->with('success', 'Schedule created');
    }

    public function update(Request $request, ScheduledExport $scheduledExport): RedirectResponse
    {
        abort_unless((int) $scheduledExport->workspace_id === (int) TenantContext::id(), 404);

        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'cron_expression' => ['sometimes', 'string', 'max:64'],
            'delivery' => ['nullable', 'array'],
            'name' => ['sometimes', 'string', 'max:120'],
        ]);

        $scheduledExport->fill($data);
        if (isset($data['cron_expression'])) {
            $scheduledExport->next_run_at = $this->nextRunAt(
                $scheduledExport->cron_expression,
                $scheduledExport->timezone,
            );
        }
        $scheduledExport->save();

        return back()->with('success', 'Schedule updated');
    }

    public function destroy(ScheduledExport $scheduledExport): RedirectResponse
    {
        abort_unless((int) $scheduledExport->workspace_id === (int) TenantContext::id(), 404);
        $scheduledExport->delete();

        return back()->with('success', 'Schedule deleted');
    }

    private function nextRunAt(string $cron, string $timezone): Carbon
    {
        // Lightweight fallback: hourly / daily presets + generic +1 hour
        return match ($cron) {
            '0 * * * *' => now($timezone)->addHour()->startOfHour()->utc(),
            '0 8 * * *' => now($timezone)->addDay()->setTime(8, 0)->utc(),
            '0 8 * * 1' => now($timezone)->next(Carbon::MONDAY)->setTime(8, 0)->utc(),
            default => now()->addHour(),
        };
    }
}
