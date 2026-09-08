<?php

namespace App\Domain\Returns\Services;

final class ReturnRiskScoreService
{
    /**
     * @param  array{
     *   return_rate: float,
     *   returned_units: int,
     *   units_sold: int,
     *   returned_amount: float,
     *   workspace_returned_amount: float,
     *   historical_rate: float|null,
     *   recent_rate: float|null,
     *   prior_rate: float|null,
     *   top_reason_share: float,
     *   defective_share: float,
     *   max_variant_rate: float|null,
     *   peer_variant_rate: float|null
     * }  $input
     * @return array{score: int, level: string, confidence: string, factors: list<array{key: string, label: string, points: float, detail: string}>}
     */
    public function calculate(array $input): array
    {
        $weights = config('returns.risk_score.weights', []);
        $factors = [];

        $rate = (float) $input['return_rate'];
        $ratePoints = $this->mapRate($rate) * ((float) ($weights['return_rate'] ?? 25) / 25);
        $factors[] = [
            'key' => 'return_rate',
            'label' => 'Tasa de devolución',
            'points' => round($ratePoints, 1),
            'detail' => round($rate * 100, 1).'% de tasa en el periodo.',
        ];

        $volumePoints = min((float) ($weights['volume'] ?? 10), log(max(1, (int) $input['returned_units']) + 1, 2) * 2);
        $factors[] = [
            'key' => 'volume',
            'label' => 'Volumen de devoluciones',
            'points' => round($volumePoints, 1),
            'detail' => (int) $input['returned_units'].' unidades devueltas.',
        ];

        $wsLoss = max(0.0001, (float) $input['workspace_returned_amount']);
        $lossShare = min(1, (float) $input['returned_amount'] / $wsLoss);
        $lossPoints = $lossShare * (float) ($weights['loss_share'] ?? 15);
        $factors[] = [
            'key' => 'loss_share',
            'label' => 'Impacto económico',
            'points' => round($lossPoints, 1),
            'detail' => round($lossShare * 100, 1).'% del importe devuelto del periodo.',
        ];

        $hist = $input['historical_rate'];
        $vsBaseline = 0.0;
        if ($hist !== null && $hist > 0) {
            $ratio = $rate / $hist;
            $vsBaseline = min((float) ($weights['vs_baseline'] ?? 20), max(0, ($ratio - 1) * 10));
            $factors[] = [
                'key' => 'vs_baseline',
                'label' => 'Vs histórico',
                'points' => round($vsBaseline, 1),
                'detail' => round($ratio, 1).'x su promedio histórico ('.round($hist * 100, 1).'%).',
            ];
        } else {
            $factors[] = [
                'key' => 'vs_baseline',
                'label' => 'Vs histórico',
                'points' => 0,
                'detail' => 'Sin baseline suficiente todavía.',
            ];
        }

        $recent = $input['recent_rate'];
        $prior = $input['prior_rate'];
        $growthPoints = 0.0;
        if ($recent !== null && $prior !== null && $prior > 0 && $recent > $prior * 1.5) {
            $growthPoints = min((float) ($weights['recent_growth'] ?? 10), (($recent / $prior) - 1) * 5);
        }
        $factors[] = [
            'key' => 'recent_growth',
            'label' => 'Crecimiento reciente',
            'points' => round($growthPoints, 1),
            'detail' => $growthPoints > 0
                ? 'Incremento reciente detectado.'
                : 'Sin spike reciente relevante.',
        ];

        $conc = min(1, (float) $input['top_reason_share']);
        $concPoints = $conc >= 0.5 ? $conc * (float) ($weights['reason_concentration'] ?? 10) : 0;
        $factors[] = [
            'key' => 'reason_concentration',
            'label' => 'Concentración de motivo',
            'points' => round($concPoints, 1),
            'detail' => round($conc * 100).'% en el motivo principal.',
        ];

        $defective = min(1, (float) $input['defective_share']);
        $defPoints = $defective * (float) ($weights['defective_boost'] ?? 5);
        $factors[] = [
            'key' => 'defective_boost',
            'label' => 'Producto defectuoso',
            'points' => round($defPoints, 1),
            'detail' => round($defective * 100).'% reporta defecto/calidad.',
        ];

        $variantPoints = 0.0;
        $maxVar = $input['max_variant_rate'];
        $peerVar = $input['peer_variant_rate'];
        if ($maxVar !== null && $peerVar !== null && $peerVar > 0 && $maxVar >= $peerVar * 3) {
            $variantPoints = (float) ($weights['variant_concentration'] ?? 5);
        }
        $factors[] = [
            'key' => 'variant_concentration',
            'label' => 'Concentración en variante',
            'points' => round($variantPoints, 1),
            'detail' => $variantPoints > 0
                ? 'Una variante concentra devoluciones anormalmente.'
                : 'Sin outlier de variante claro.',
        ];

        $raw = array_sum(array_column($factors, 'points'));
        $confidence = $this->confidence((int) $input['units_sold']);
        $multiplier = (float) (config('returns.risk_score.confidence_multipliers.'.$confidence) ?? 1);
        $score = (int) round(min(100, $raw * $multiplier));

        $levels = config('returns.risk_score.levels', []);
        $level = match (true) {
            $score <= (int) ($levels['normal_max'] ?? 39) => 'normal',
            $score <= (int) ($levels['attention_max'] ?? 59) => 'attention',
            $score <= (int) ($levels['high_max'] ?? 79) => 'high',
            default => 'critical',
        };

        $minSalesCritical = (int) config('returns.risk_score.min_sales_for_critical', 30);
        if ($level === 'critical' && (int) $input['units_sold'] < $minSalesCritical) {
            $level = 'attention';
        }

        return [
            'score' => $score,
            'level' => $level,
            'confidence' => $confidence,
            'factors' => $factors,
        ];
    }

    private function mapRate(float $rate): float
    {
        $t = config('returns.rate_thresholds', []);
        if ($rate < (float) ($t['normal_max'] ?? 0.03)) {
            return 5;
        }
        if ($rate < (float) ($t['attention_max'] ?? 0.05)) {
            return 12;
        }
        if ($rate < (float) ($t['high_max'] ?? 0.10)) {
            return 20;
        }

        return 25;
    }

    private function confidence(int $unitsSold): string
    {
        $buckets = config('returns.risk_score.confidence_sales_buckets', []);
        if ($unitsSold < (int) ($buckets['low'] ?? 5)) {
            return 'low';
        }
        if ($unitsSold < (int) ($buckets['medium'] ?? 30)) {
            return 'medium';
        }
        if ($unitsSold < (int) ($buckets['high'] ?? 100)) {
            return 'high';
        }

        return 'very_high';
    }
}
