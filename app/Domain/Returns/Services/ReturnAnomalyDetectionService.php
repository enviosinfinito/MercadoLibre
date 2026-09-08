<?php

namespace App\Domain\Returns\Services;

use App\Models\ReturnAlert;
use Illuminate\Support\Carbon;

final class ReturnAnomalyDetectionService
{
    public function __construct(
        private readonly ReturnProductAnalyticsService $products,
        private readonly ReturnBaselineService $baseline,
    ) {}

    /**
     * @param  list<int>|null  $connectionIds
     * @return list<ReturnAlert>
     */
    public function detect(int $workspaceId, Carbon $start, Carbon $end, ?array $connectionIds = null): array
    {
        $rows = $this->products->productRows($workspaceId, $start, $end, $connectionIds, limit: 200);
        $alerts = [];
        $cfg = config('returns.anomaly', []);

        foreach ($rows as $row) {
            if (($row['units_sold'] ?? 0) < (int) ($cfg['min_sales'] ?? 10)) {
                continue;
            }
            if (($row['return_count'] ?? 0) < (int) ($cfg['min_returns'] ?? 3)) {
                continue;
            }

            $signals = [];
            $level = 'attention';

            $thresholds = config('returns.rate_thresholds', []);
            if ($row['return_rate'] >= (float) ($thresholds['high_max'] ?? 0.10)) {
                $signals[] = 'Tasa absoluta crítica ('.round($row['return_rate'] * 100, 1).'%).';
                $level = 'critical';
            } elseif ($row['return_rate'] >= (float) ($thresholds['attention_max'] ?? 0.05)) {
                $signals[] = 'Tasa absoluta elevada ('.round($row['return_rate'] * 100, 1).'%).';
                $level = 'high';
            }

            $baseline = $this->baseline->forProduct($workspaceId, $row['product_id'], $row['ml_item_id'], $end);
            if ($baseline['mean'] !== null && $baseline['mean'] > 0
                && $row['return_rate'] >= $baseline['mean'] * (float) ($cfg['baseline_multiplier'] ?? 2)
            ) {
                $signals[] = 'Está '.round($row['return_rate'] / $baseline['mean'], 1).'x sobre su histórico.';
                $level = $level === 'critical' ? 'critical' : 'high';
            }

            foreach ($row['risk_factors'] ?? [] as $factor) {
                if (($factor['key'] ?? '') === 'recent_growth' && ($factor['points'] ?? 0) > 0) {
                    $signals[] = 'Incremento reciente detectado.';
                }
                if (($factor['key'] ?? '') === 'reason_concentration' && ($factor['points'] ?? 0) > 0) {
                    $signals[] = $factor['detail'] ?? 'Concentración de motivo.';
                }
            }

            if ($signals === []) {
                continue;
            }

            $dedupe = md5(($row['product_id'] ?? '').'|'.($row['ml_item_id'] ?? '').'|'.$end->toDateString().'|'.implode(',', $signals));
            $alert = ReturnAlert::query()->updateOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'dedupe_key' => $dedupe,
                ],
                [
                    'product_id' => $row['product_id'],
                    'ml_item_id' => $row['ml_item_id'],
                    'alert_type' => 'product_anomaly',
                    'level' => $level,
                    'title' => 'Anomalía: '.($row['name'] ?? 'Producto'),
                    'body' => implode(' ', $signals),
                    'status' => 'open',
                    'context' => [
                        'signals' => $signals,
                        'risk_score' => $row['risk_score'],
                        'return_rate' => $row['return_rate'],
                    ],
                    'triggered_at' => now(),
                ],
            );
            $alerts[] = $alert;
        }

        return $alerts;
    }
}
