<?php

namespace App\Http\Controllers\Analytics;

use App\Domain\Analytics\Catalog\DatasetCatalog;
use App\Domain\Analytics\Query\QueryAst;
use App\Domain\Analytics\Query\QueryEngine;
use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AnalyticsReport;
use App\Services\Export\ExportReportOrchestrator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function explore(Request $request, QueryEngine $engine): Response
    {
        $this->authorize('viewAny', AnalyticsReport::class);

        $preview = null;
        $queryInput = $request->input('query');

        if (is_array($queryInput)) {
            try {
                $ast = QueryAst::fromArray($queryInput);
                $preview = $engine->execute($ast);
            } catch (\Throwable $e) {
                $preview = ['error' => $e->getMessage(), 'columns' => [], 'rows' => []];
            }
        }

        $reports = AnalyticsReport::query()
            ->where('workspace_id', TenantContext::id())
            ->where(function ($q) use ($request) {
                $q->where('owner_user_id', $request->user()->id)
                    ->orWhere('visibility', AnalyticsReport::VISIBILITY_WORKSPACE);
            })
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return Inertia::render('Analytics/Explore', [
            'catalog' => DatasetCatalog::toArray(),
            'query' => is_array($queryInput) ? $queryInput : [
                'dataset' => 'profit',
                'dimensions' => ['ordered_at:day'],
                'measures' => [
                    ['field' => 'profit', 'agg' => 'sum', 'alias' => 'profit_sum'],
                    ['field' => 'revenue', 'agg' => 'sum', 'alias' => 'revenue_sum'],
                ],
                'filters' => [],
                'sort' => [['field' => 'ordered_at_day', 'dir' => 'asc']],
                'limit' => 500,
            ],
            'preview' => $preview,
            'reports' => $reports,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', AnalyticsReport::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['required', 'in:personal,workspace'],
            'viz_type' => ['required', 'string', 'in:kpi,line,bar,pie,table,pivot'],
            'query' => ['required', 'array'],
            'viz_options' => ['nullable', 'array'],
        ]);

        QueryAst::fromArray($data['query']);

        $report = AnalyticsReport::query()->create([
            'workspace_id' => TenantContext::id(),
            'owner_user_id' => $request->user()->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'visibility' => $data['visibility'],
            'viz_type' => $data['viz_type'],
            'query' => $data['query'],
            'viz_options' => $data['viz_options'] ?? null,
        ]);

        return redirect()->route('analytics.explore', ['report' => $report->id])
            ->with('success', 'Report saved');
    }

    public function update(Request $request, AnalyticsReport $report): RedirectResponse
    {
        $this->authorize('update', $report);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['sometimes', 'in:personal,workspace'],
            'viz_type' => ['sometimes', 'string', 'in:kpi,line,bar,pie,table,pivot'],
            'query' => ['sometimes', 'array'],
            'viz_options' => ['nullable', 'array'],
        ]);

        if (isset($data['query'])) {
            QueryAst::fromArray($data['query']);
        }

        $report->update($data);

        return back()->with('success', 'Report updated');
    }

    public function destroy(AnalyticsReport $report): RedirectResponse
    {
        $this->authorize('delete', $report);
        $report->delete();

        return redirect()->route('analytics.explore')->with('success', 'Report deleted');
    }

    public function export(Request $request, AnalyticsReport $report, ExportReportOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('view', $report);

        $orchestrator->start(
            user: $request->user(),
            workspaceId: (int) TenantContext::id(),
            targetModule: 'analytics_query',
            selectionMode: 'filter',
            filters: ['analytics_report_id' => $report->id],
            query: $report->query,
            source: 'analytics_report',
        );

        return redirect()->route('exports.index')->with('success', 'Export queued');
    }

    public function exportQuery(Request $request, ExportReportOrchestrator $orchestrator): RedirectResponse
    {
        $data = $request->validate([
            'query' => ['required', 'array'],
            'format' => ['nullable', 'string', 'in:csv,xlsx'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        QueryAst::fromArray($data['query']);

        $orchestrator->start(
            user: $request->user(),
            workspaceId: (int) TenantContext::id(),
            targetModule: 'analytics_query',
            selectionMode: 'filter',
            filters: ['name' => $data['name'] ?? 'analytics_export'],
            query: $data['query'],
            source: 'analytics_explore',
        );

        return redirect()->route('exports.index')->with('success', 'Export queued');
    }
}
