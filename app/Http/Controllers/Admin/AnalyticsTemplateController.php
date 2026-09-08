<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Analytics\Catalog\DatasetCatalog;
use App\Domain\Analytics\Query\QueryAst;
use App\Http\Controllers\Controller;
use App\Models\AnalyticsDashboard;
use App\Models\AnalyticsWidget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsTemplateController extends Controller
{
    public function index(): Response
    {
        $this->authorize('manageTemplates', AnalyticsDashboard::class);

        $templates = AnalyticsDashboard::query()
            ->where('visibility', AnalyticsDashboard::VISIBILITY_PLATFORM_TEMPLATE)
            ->withCount('widgets')
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Analytics/Templates/Index', [
            'templates' => $templates,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('manageTemplates', AnalyticsDashboard::class);

        return Inertia::render('Admin/Analytics/Templates/Edit', [
            'dashboard' => null,
            'catalog' => DatasetCatalog::toArray(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manageTemplates', AnalyticsDashboard::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'slug' => ['nullable', 'string', 'max:80'],
            'is_home' => ['sometimes', 'boolean'],
            'global_filters' => ['nullable', 'array'],
            'widgets' => ['nullable', 'array'],
            'widgets.*.type' => ['required_with:widgets', 'string', 'in:kpi,line,bar,pie,table,pivot'],
            'widgets.*.title' => ['required_with:widgets', 'string', 'max:120'],
            'widgets.*.query' => ['required_with:widgets', 'array'],
            'widgets.*.viz_options' => ['nullable', 'array'],
            'widgets.*.grid' => ['nullable', 'array'],
        ]);

        if (! empty($data['is_home'])) {
            AnalyticsDashboard::query()
                ->where('visibility', AnalyticsDashboard::VISIBILITY_PLATFORM_TEMPLATE)
                ->where('is_home', true)
                ->update(['is_home' => false]);
        }

        $dashboard = AnalyticsDashboard::query()->create([
            'workspace_id' => null,
            'owner_user_id' => $request->user()->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'visibility' => AnalyticsDashboard::VISIBILITY_PLATFORM_TEMPLATE,
            'slug' => $data['slug'] ?? null,
            'is_home' => (bool) ($data['is_home'] ?? false),
            'global_filters' => $data['global_filters'] ?? null,
            'layout' => ['cols' => 12],
        ]);

        foreach ($data['widgets'] ?? [] as $index => $widgetData) {
            QueryAst::fromArray($widgetData['query']);
            AnalyticsWidget::query()->create([
                'analytics_dashboard_id' => $dashboard->id,
                'type' => $widgetData['type'],
                'title' => $widgetData['title'],
                'query' => $widgetData['query'],
                'viz_options' => $widgetData['viz_options'] ?? null,
                'grid' => $widgetData['grid'] ?? ['x' => ($index % 2) * 6, 'y' => intdiv($index, 2) * 4, 'w' => 6, 'h' => 4],
                'sort_order' => $index,
            ]);
        }

        return redirect()->route('admin.analytics.templates.edit', $dashboard)
            ->with('success', 'Template created');
    }

    public function edit(AnalyticsDashboard $dashboard): Response
    {
        $this->authorize('manageTemplates', AnalyticsDashboard::class);
        abort_unless($dashboard->isPlatformTemplate(), 404);
        $dashboard->load('widgets');

        return Inertia::render('Admin/Analytics/Templates/Edit', [
            'dashboard' => $dashboard,
            'catalog' => DatasetCatalog::toArray(),
        ]);
    }

    public function update(Request $request, AnalyticsDashboard $dashboard): RedirectResponse
    {
        $this->authorize('manageTemplates', AnalyticsDashboard::class);
        abort_unless($dashboard->isPlatformTemplate(), 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'slug' => ['nullable', 'string', 'max:80'],
            'is_home' => ['sometimes', 'boolean'],
            'global_filters' => ['nullable', 'array'],
            'layout' => ['nullable', 'array'],
            'widgets' => ['sometimes', 'array'],
            'widgets.*.id' => ['nullable', 'integer'],
            'widgets.*.type' => ['required_with:widgets', 'string', 'in:kpi,line,bar,pie,table,pivot'],
            'widgets.*.title' => ['required_with:widgets', 'string', 'max:120'],
            'widgets.*.query' => ['required_with:widgets', 'array'],
            'widgets.*.viz_options' => ['nullable', 'array'],
            'widgets.*.grid' => ['nullable', 'array'],
            'widgets.*.sort_order' => ['nullable', 'integer'],
        ]);

        if (! empty($data['is_home'])) {
            AnalyticsDashboard::query()
                ->where('visibility', AnalyticsDashboard::VISIBILITY_PLATFORM_TEMPLATE)
                ->where('is_home', true)
                ->where('id', '!=', $dashboard->id)
                ->update(['is_home' => false]);
        }

        $dashboard->fill(collect($data)->except('widgets')->all());
        $dashboard->workspace_id = null;
        $dashboard->visibility = AnalyticsDashboard::VISIBILITY_PLATFORM_TEMPLATE;
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
                    $keepIds[] = $dashboard->widgets()->create($payload)->id;
                }
            }
            $dashboard->widgets()->whereNotIn('id', $keepIds)->delete();
        }

        return back()->with('success', 'Template saved');
    }

    public function destroy(AnalyticsDashboard $dashboard): RedirectResponse
    {
        $this->authorize('manageTemplates', AnalyticsDashboard::class);
        abort_unless($dashboard->isPlatformTemplate(), 404);
        $dashboard->delete();

        return redirect()->route('admin.analytics.templates.index')
            ->with('success', 'Template deleted');
    }
}
