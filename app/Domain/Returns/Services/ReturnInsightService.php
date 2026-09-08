<?php

namespace App\Domain\Returns\Services;

use App\Models\ReturnInsight;
use Illuminate\Support\Carbon;

final class ReturnInsightService
{
    public function __construct(
        private readonly ReturnProductAnalyticsService $products,
        private readonly ReturnReasonAnalysisService $reasons,
        private readonly ReturnAnalyticsService $analytics,
    ) {}

    /**
     * @param  list<int>|null  $connectionIds
     * @return list<array<string, mixed>>
     */
    public function generate(
        int $workspaceId,
        Carbon $start,
        Carbon $end,
        string $periodKey,
        ?array $connectionIds = null,
    ): array {
        ReturnInsight::query()
            ->where('workspace_id', $workspaceId)
            ->where('period_key', $periodKey)
            ->delete();

        $insights = [];
        $rows = $this->products->productRows($workspaceId, $start, $end, $connectionIds, limit: 100);
        $kpis = $this->analytics->windowMetrics($workspaceId, $start, $end, $connectionIds);

        if ($rows !== []) {
            $top = $rows[0];
            if (($top['risk_score'] ?? 0) >= 60) {
                $insights[] = $this->store($workspaceId, $periodKey, 'top_risk', 'warning',
                    ($top['name'] ?? 'Un producto').' requiere atención',
                    'Risk Score '.$top['risk_score'].'/100 con tasa '.round($top['return_rate'] * 100, 1).'%.',
                    ['product_id' => $top['product_id'], 'ml_item_id' => $top['ml_item_id']],
                );
            }

            $totalLoss = max(0.01, array_sum(array_column($rows, 'returned_amount')));
            $cum = 0;
            $paretoCount = 0;
            foreach ($rows as $row) {
                $cum += $row['returned_amount'];
                $paretoCount++;
                if ($cum / $totalLoss >= 0.8) {
                    break;
                }
            }
            if ($paretoCount > 0 && $paretoCount < count($rows)) {
                $insights[] = $this->store($workspaceId, $periodKey, 'pareto', 'info',
                    'Concentración 80/20',
                    "El 80% del importe devuelto proviene de {$paretoCount} productos.",
                    ['products' => $paretoCount],
                );
            }
        }

        $reasons = $this->reasons->global($workspaceId, $start, $end, $connectionIds);
        if (($reasons[0]['share'] ?? 0) >= 0.4) {
            $insights[] = $this->store($workspaceId, $periodKey, 'reason_dominance', 'warning',
                'Motivo dominante: '.$reasons[0]['label'],
                round($reasons[0]['share'] * 100).'% de las devoluciones concentran este motivo.',
                ['group' => $reasons[0]['group']],
            );
        }

        foreach ($rows as $row) {
            if (! empty($row['risk_factors'])) {
                foreach ($row['risk_factors'] as $factor) {
                    if (($factor['key'] ?? '') === 'vs_baseline' && ($factor['points'] ?? 0) >= 10) {
                        $insights[] = $this->store($workspaceId, $periodKey, 'baseline_breach', 'warning',
                            ($row['name'] ?? 'Producto').' sobre su histórico',
                            (string) ($factor['detail'] ?? ''),
                            ['product_id' => $row['product_id']],
                        );
                        break 2;
                    }
                }
            }
        }

        if ($kpis['return_count'] === 0) {
            $insights[] = $this->store($workspaceId, $periodKey, 'empty', 'info',
                'Sin devoluciones en el periodo',
                'Todavía no existen suficientes devoluciones para calcular patrones.',
                [],
            );
        }

        return array_map(fn (ReturnInsight $i) => [
            'id' => $i->id,
            'type' => $i->insight_type,
            'severity' => $i->severity,
            'title' => $i->title,
            'body' => $i->body,
            'context' => $i->context,
        ], $insights);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function store(
        int $workspaceId,
        string $periodKey,
        string $type,
        string $severity,
        string $title,
        string $body,
        array $context,
    ): ReturnInsight {
        return ReturnInsight::query()->create([
            'workspace_id' => $workspaceId,
            'insight_type' => $type,
            'severity' => $severity,
            'title' => $title,
            'body' => $body,
            'context' => $context,
            'period_key' => $periodKey,
            'generated_at' => now(),
        ]);
    }
}
