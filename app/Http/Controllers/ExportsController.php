<?php

namespace App\Http\Controllers;

use App\Domain\Analytics\Query\QueryAst;
use App\Domain\Shared\Support\TenantContext;
use App\Services\Export\ExportReportOrchestrator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Legacy entrypoints kept for Analytics Explore/Show.
 * Prefer ExportPlatformController::start for new UI.
 */
class ExportsController extends Controller
{
    public function store(Request $request, ExportReportOrchestrator $orchestrator): RedirectResponse
    {
        $data = $request->validate([
            'report_type' => ['required', 'string', 'max:100'],
            'format' => ['nullable', 'string', 'in:csv,xlsx'],
            'query' => ['nullable', 'array'],
            'analytics_report_id' => ['nullable', 'integer', 'exists:analytics_reports,id'],
            'filters' => ['nullable', 'array'],
        ]);

        if (! empty($data['query'])) {
            QueryAst::fromArray($data['query']);
        }

        $module = ($data['report_type'] === 'orders' && empty($data['query']))
            ? 'orders'
            : 'analytics_query';

        $orchestrator->start(
            user: $request->user(),
            workspaceId: (int) TenantContext::id(),
            targetModule: $module,
            selectionMode: 'filter',
            filters: $data['filters'] ?? [],
            query: $data['query'] ?? null,
            source: 'legacy_store',
        );

        return redirect()->route('exports.index')->with('success', 'Export queued');
    }
}
