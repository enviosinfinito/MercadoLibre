<?php

namespace App\Http\Controllers\Returns;

use App\Domain\Returns\Services\BuildReturnPeriodComparison;
use App\Domain\Returns\Services\ReturnAnalyticsService;
use App\Domain\Returns\Services\ReturnAnomalyDetectionService;
use App\Domain\Returns\Services\ReturnInsightService;
use App\Domain\Returns\Services\ReturnProductAnalyticsService;
use App\Domain\Returns\Services\ReturnReasonAnalysisService;
use App\Domain\Returns\Services\ReturnVisualizationService;
use App\Domain\Returns\Support\ReturnPeriodResolver;
use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ReturnsDashboardController extends Controller
{
    public function __construct(
        private readonly ReturnPeriodResolver $periodResolver,
        private readonly ReturnAnalyticsService $analytics,
        private readonly ReturnProductAnalyticsService $products,
        private readonly ReturnReasonAnalysisService $reasons,
        private readonly ReturnInsightService $insights,
        private readonly ReturnAnomalyDetectionService $anomalies,
        private readonly ReturnVisualizationService $visualizations,
        private readonly BuildReturnPeriodComparison $comparison,
    ) {}

    public function index(Request $request): Response
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $connections = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->orderBy('display_name')
            ->get(['id', 'provider', 'external_user_id', 'display_name', 'color', 'status']);

        $period = $this->periodResolver->resolve(
            $request->string('period')->toString() ?: 'last_30_days',
            $request->input('from'),
            $request->input('to'),
        );

        $connectionIds = $this->resolvedConnectionIds($request, $connections);

        $kpis = $this->analytics->kpis(
            $workspaceId,
            $period['start'],
            $period['end'],
            $period['previous_start'],
            $period['previous_end'],
            $connectionIds,
        );

        $productRows = $this->products->productRows(
            $workspaceId,
            $period['start'],
            $period['end'],
            $connectionIds,
            limit: 100,
            sort: $request->string('sort')->toString() ?: 'risk_score',
            q: $request->input('q'),
            minRate: $request->filled('min_rate') ? (float) $request->input('min_rate') / 100 : null,
            maxRate: $request->filled('max_rate') ? (float) $request->input('max_rate') / 100 : null,
            anomaliesOnly: $request->boolean('anomalies_only'),
            aboveHistoricalOnly: $request->boolean('above_historical'),
        );

        $insights = $this->insights->generate(
            $workspaceId,
            $period['start'],
            $period['end'],
            $period['key'],
            $connectionIds,
        );

        $this->anomalies->detect($workspaceId, $period['start'], $period['end'], $connectionIds);

        $attention = array_values(array_filter(
            $productRows,
            fn (array $row) => in_array($row['risk_level'], ['attention', 'high', 'critical'], true),
        ));
        $attention = array_slice($attention, 0, 8);

        return Inertia::render('Returns/Index', [
            'period' => [
                'preset' => $request->input('period', 'last_30_days'),
                'from' => $period['start']->toDateString(),
                'to' => $period['end']->toDateString(),
                'label' => $period['label'],
                'key' => $period['key'],
            ],
            'filters' => [
                'q' => $request->input('q', ''),
                'period' => $request->input('period', 'last_30_days'),
                'connection_ids' => $connectionIds ?? [],
                'min_rate' => $request->input('min_rate'),
                'max_rate' => $request->input('max_rate'),
                'anomalies_only' => $request->boolean('anomalies_only'),
                'above_historical' => $request->boolean('above_historical'),
                'sort' => $request->input('sort', 'risk_score'),
                'tab' => $request->input('tab', 'products'),
                'from' => $request->input('from', $period['start']->toDateString()),
                'to' => $request->input('to', $period['end']->toDateString()),
            ],
            'connections' => $connections,
            'kpis' => $kpis,
            'insights' => $insights,
            'attention_products' => $attention,
            'products' => $productRows,
            'reasons' => $this->reasons->global($workspaceId, $period['start'], $period['end'], $connectionIds),
            'categories' => $this->visualizations->categories($workspaceId, $period['start'], $period['end'], $connectionIds),
            'pareto' => $this->visualizations->pareto($workspaceId, $period['start'], $period['end'], $connectionIds),
            'scatter' => $this->visualizations->scatter($workspaceId, $period['start'], $period['end'], $connectionIds),
            'heatmap' => $this->visualizations->heatmap($workspaceId, $period['start'], $period['end'], $connectionIds),
            'days_to_return' => $this->visualizations->daysToReturnDistribution($workspaceId, $period['start'], $period['end'], $connectionIds),
            'logistics' => $this->visualizations->logisticsCorrelation($workspaceId, $period['start'], $period['end'], $connectionIds),
            'geo' => $this->visualizations->geoDistribution($workspaceId, $period['start'], $period['end'], $connectionIds),
            'comparison' => $this->comparison->global(
                $workspaceId,
                $period['start'],
                $period['end'],
                $period['previous_start'],
                $period['previous_end'],
                $connectionIds,
            ),
            'empty_hint' => $kpis['total_returns'] === 0
                ? 'Todavía no existen suficientes ventas/devoluciones para calcular patrones de devolución. Si ya syncaste reclamos, verifica que las órdenes estén sincronizadas y ejecuta postsale:relink-claims.'
                : null,
        ]);
    }

    /**
     * @param  Collection<int, Connection>  $connections
     * @return list<int>|null
     */
    private function resolvedConnectionIds(Request $request, Collection $connections): ?array
    {
        $allowed = $connections->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($allowed === []) {
            return null;
        }

        $raw = $request->input('connection_ids', []);
        if (! is_array($raw) || $raw === []) {
            $single = $request->input('connection_id');
            if ($single !== null && $single !== '') {
                $raw = [$single];
            } else {
                return null;
            }
        }

        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id) => (int) $id, $raw),
            static fn (int $id) => in_array($id, $allowed, true),
        )));

        if ($ids === [] || count($ids) === count($allowed)) {
            return null;
        }

        sort($ids);

        return $ids;
    }
}
