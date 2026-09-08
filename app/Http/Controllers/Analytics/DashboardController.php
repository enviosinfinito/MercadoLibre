<?php

namespace App\Http\Controllers\Analytics;

use App\Domain\Analytics\Actions\CloneDashboard;
use App\Domain\Analytics\Actions\MergeGlobalFilters;
use App\Domain\Analytics\Catalog\DatasetCatalog;
use App\Domain\Analytics\Query\QueryAst;
use App\Domain\Analytics\Query\QueryEngine;
use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Filters\Analytics\AnalyticsFilterRegistry;
use App\Http\Requests\Analytics\ShowFilterRequest;
use App\Models\AnalyticsDashboard;
use App\Models\AnalyticsWidget;
use App\Models\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AnalyticsDashboard::class);

        $workspaceId = TenantContext::id();
        $userId = $request->user()->id;

        $templates = AnalyticsDashboard::query()
            ->where('visibility', AnalyticsDashboard::VISIBILITY_PLATFORM_TEMPLATE)
            ->withCount('widgets')
            ->orderBy('name')
            ->get();

        $workspace = AnalyticsDashboard::query()
            ->where('workspace_id', $workspaceId)
            ->where('visibility', AnalyticsDashboard::VISIBILITY_WORKSPACE)
            ->withCount('widgets')
            ->orderBy('name')
            ->get();

        $personal = AnalyticsDashboard::query()
            ->where('workspace_id', $workspaceId)
            ->where('visibility', AnalyticsDashboard::VISIBILITY_PERSONAL)
            ->where('owner_user_id', $userId)
            ->withCount('widgets')
            ->orderBy('name')
            ->get();

        return Inertia::render('Analytics/Index', [
            'templates' => $templates,
            'workspaceDashboards' => $workspace,
            'personalDashboards' => $personal,
            'catalog' => DatasetCatalog::toArray(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', AnalyticsDashboard::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['required', 'in:workspace,personal'],
            'global_filters' => ['nullable', 'array'],
        ]);

        $dashboard = AnalyticsDashboard::query()->create([
            'workspace_id' => TenantContext::id(),
            'owner_user_id' => $request->user()->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'visibility' => $data['visibility'],
            'global_filters' => $data['global_filters'] ?? null,
            'layout' => ['cols' => 12],
        ]);

        return redirect()->route('analytics.dashboards.edit', $dashboard)
            ->with('success', 'Dashboard created');
    }

    public function show(
        ShowFilterRequest $request,
        AnalyticsDashboard $dashboard,
        AnalyticsFilterRegistry $filterRegistry,
    ): Response {
        $this->authorize('view', $dashboard);
        $dashboard->load('widgets');

        $runtimeFilters = $filterRegistry->normalize($request->filters());

        $engine = app(QueryEngine::class);
        $merger = app(MergeGlobalFilters::class);
        $widgetResults = [];

        foreach ($dashboard->widgets as $widget) {
            try {
                $query = $merger->handle(
                    $widget->query ?? [],
                    $dashboard->global_filters,
                    array_filter($runtimeFilters, fn ($v) => $v !== null && $v !== ''),
                );
                $ast = QueryAst::fromArray($query);
                $widgetResults[$widget->id] = $engine->execute($ast);
            } catch (\Throwable $e) {
                $widgetResults[$widget->id] = [
                    'columns' => [],
                    'rows' => [],
                    'meta' => ['error' => $e->getMessage()],
                ];
            }
        }

        return Inertia::render('Analytics/Show', [
            'dashboard' => $dashboard,
            'widgetResults' => $widgetResults,
            'connections' => Connection::query()
                ->where('workspace_id', TenantContext::id())
                ->orderBy('id')
                ->get(['id', 'provider', 'external_user_id', 'display_name', 'site_id', 'color', 'status']),
            'filters' => $runtimeFilters,
            'canEdit' => $request->user()->can('update', $dashboard),
        ]);
    }

    public function edit(Request $request, AnalyticsDashboard $dashboard): Response
    {
        $this->authorize('update', $dashboard);
        $dashboard->load('widgets');

        return Inertia::render('Analytics/Edit', [
            'dashboard' => $dashboard,
            'catalog' => DatasetCatalog::toArray(),
            'connections' => Connection::query()
                ->where('workspace_id', TenantContext::id())
                ->orderBy('id')
                ->get(['id', 'provider', 'external_user_id', 'display_name', 'site_id', 'color', 'status']),
        ]);
    }

    public function update(Request $request, AnalyticsDashboard $dashboard): RedirectResponse
    {
        $this->authorize('update', $dashboard);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['sometimes', 'in:workspace,personal,platform_template'],
            'layout' => ['nullable', 'array'],
            'global_filters' => ['nullable', 'array'],
            'is_home' => ['sometimes', 'boolean'],
            'widgets' => ['sometimes', 'array'],
            'widgets.*.id' => ['nullable', 'integer'],
            'widgets.*.type' => ['required_with:widgets', 'string', 'in:kpi,line,bar,pie,table,pivot'],
            'widgets.*.title' => ['required_with:widgets', 'string', 'max:120'],
            'widgets.*.query' => ['required_with:widgets', 'array'],
            'widgets.*.viz_options' => ['nullable', 'array'],
            'widgets.*.grid' => ['nullable', 'array'],
            'widgets.*.sort_order' => ['nullable', 'integer'],
        ]);

        if (isset($data['visibility']) && $data['visibility'] === AnalyticsDashboard::VISIBILITY_PLATFORM_TEMPLATE) {
            abort_unless($request->user()->is_platform_admin, 403);
        }

        $dashboard->fill(collect($data)->except('widgets')->all());
        $dashboard->save();

        if (isset($data['widgets'])) {
            $keepIds = [];
            foreach ($data['widgets'] as $index => $widgetData) {
                QueryAst::fromArray($widgetData['query']);
                $payload = [
                    'type' => $widgetData['type'],
                    'title' => $widgetData['title'],
                    'query' => $widgetData['query'],
                    'viz_options' => $widgetData['viz_options'] ?? null,
                    'grid' => $widgetData['grid'] ?? ['x' => 0, 'y' => $index * 4, 'w' => 6, 'h' => 4],
                    'sort_order' => $widgetData['sort_order'] ?? $index,
                ];
                if (! empty($widgetData['id'])) {
                    $widget = AnalyticsWidget::query()
                        ->where('analytics_dashboard_id', $dashboard->id)
                        ->whereKey($widgetData['id'])
                        ->firstOrFail();
                    $widget->update($payload);
                    $keepIds[] = $widget->id;
                } else {
                    $widget = $dashboard->widgets()->create($payload);
                    $keepIds[] = $widget->id;
                }
            }
            $dashboard->widgets()->whereNotIn('id', $keepIds)->delete();
        }

        return redirect()->route('analytics.dashboards.show', $dashboard)
            ->with('success', 'Dashboard saved');
    }

    public function destroy(AnalyticsDashboard $dashboard): RedirectResponse
    {
        $this->authorize('delete', $dashboard);
        $dashboard->delete();

        return redirect()->route('analytics.dashboards.index')
            ->with('success', 'Dashboard deleted');
    }

    public function clone(
        Request $request,
        AnalyticsDashboard $dashboard,
        CloneDashboard $cloner,
    ): RedirectResponse {
        $this->authorize('clone', $dashboard);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'visibility' => ['nullable', 'in:workspace,personal'],
        ]);

        $clone = $cloner->handle(
            $dashboard,
            TenantContext::id(),
            $request->user(),
            $data['visibility'] ?? AnalyticsDashboard::VISIBILITY_PERSONAL,
            $data['name'] ?? null,
        );

        return redirect()->route('analytics.dashboards.edit', $clone)
            ->with('success', 'Dashboard cloned');
    }

    public function query(Request $request, QueryEngine $engine): JsonResponse
    {
        $data = $request->validate([
            'query' => ['required', 'array'],
        ]);

        try {
            $ast = QueryAst::fromArray($data['query']);
            $result = $engine->execute($ast);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    public function catalog(): JsonResponse
    {
        return response()->json(['datasets' => DatasetCatalog::toArray()]);
    }

    public function drillthrough(Request $request, QueryEngine $engine): JsonResponse
    {
        $data = $request->validate([
            'query' => ['required', 'array'],
            'filters' => ['nullable', 'array'],
        ]);

        $query = $data['query'];
        $query['filters'] = array_values(array_merge($query['filters'] ?? [], $data['filters'] ?? []));
        $query['dimensions'] = $query['dimensions'] ?? [];
        // For drill-through prefer a flat table: keep dims, ensure limit
        $query['limit'] = min((int) ($query['limit'] ?? 500), 500);
        unset($query['pivot']);

        try {
            $ast = QueryAst::fromArray($query);
            $result = $engine->executeUncached($ast);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }
}
