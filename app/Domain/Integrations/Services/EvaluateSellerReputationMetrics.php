<?php

namespace App\Domain\Integrations\Services;

final class EvaluateSellerReputationMetrics
{
    /** @var list<string> */
    private const BAND_ORDER = ['leader', 'green', 'yellow', 'orange', 'red'];

    /**
     * @param  array<string, mixed>  $metrics  seller_reputation.metrics from ML
     * @return array{
     *   site_id: string,
     *   protected: bool,
     *   protection_end_date: string|null,
     *   real_level: string|null,
     *   drivers: list<array{metric: string, rate: float|null, value: int|null, band: string, threshold: float|null, used_excluded: bool}>,
     *   limiting_drivers: list<array{metric: string, rate: float|null, value: int|null, band: string, threshold: float|null, used_excluded: bool}>,
     *   worst_band: string|null,
     *   rates: array{claims: float|null, cancellations: float|null, delayed_handling_time: float|null}
     * }
     */
    public function execute(string $siteId, array $metrics, ?string $realLevel = null, ?string $protectionEndDate = null): array
    {
        $site = strtoupper(trim($siteId));
        $thresholds = config('mercadolibre_reputation.thresholds');
        if (! is_array($thresholds) || ! isset($thresholds[$site])) {
            $site = (string) config('mercadolibre_reputation.fallback_site', 'MLM');
        }

        $siteThresholds = is_array($thresholds[$site] ?? null) ? $thresholds[$site] : [];
        $protected = ($realLevel !== null && $realLevel !== '') || ($protectionEndDate !== null && $protectionEndDate !== '');

        $drivers = [];
        $rates = [
            'claims' => null,
            'cancellations' => null,
            'delayed_handling_time' => null,
        ];

        foreach (['claims', 'cancellations', 'delayed_handling_time'] as $metric) {
            $block = is_array($metrics[$metric] ?? null) ? $metrics[$metric] : [];
            $resolved = $this->resolveRate($block, $protected);
            $rates[$metric] = $resolved['rate'];
            $bandThresholds = is_array($siteThresholds[$metric] ?? null) ? $siteThresholds[$metric] : [];
            $band = $resolved['rate'] === null
                ? 'green'
                : $this->bandForRate((float) $resolved['rate'], $bandThresholds);
            $thresholdForBand = isset($bandThresholds[$band]) && is_numeric($bandThresholds[$band])
                ? (float) $bandThresholds[$band]
                : (isset($bandThresholds['orange']) && is_numeric($bandThresholds['orange'])
                    ? (float) $bandThresholds['orange']
                    : null);

            $drivers[] = [
                'metric' => $metric,
                'rate' => $resolved['rate'],
                'value' => $resolved['value'],
                'band' => $band,
                'threshold' => $thresholdForBand,
                'used_excluded' => $resolved['used_excluded'],
            ];
        }

        $worstBand = $this->worstBand(array_column($drivers, 'band'));

        // Drivers that equal the worst band (what limits the thermometer).
        $limiting = array_values(array_filter(
            $drivers,
            static fn (array $d) => $d['band'] === $worstBand,
        ));

        return [
            'site_id' => $site,
            'protected' => $protected,
            'protection_end_date' => $protectionEndDate,
            'real_level' => $realLevel,
            'drivers' => $drivers,
            'limiting_drivers' => $limiting,
            'worst_band' => $worstBand,
            'rates' => $rates,
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array{rate: float|null, value: int|null, used_excluded: bool}
     */
    private function resolveRate(array $block, bool $preferExcluded): array
    {
        $excluded = is_array($block['excluded'] ?? null) ? $block['excluded'] : null;
        $usedExcluded = false;
        $rate = null;
        $value = null;

        if ($preferExcluded && $excluded !== null) {
            if (isset($excluded['real_rate']) && is_numeric($excluded['real_rate'])) {
                $rate = (float) $excluded['real_rate'];
                $usedExcluded = true;
            }
            if (isset($excluded['real_value']) && is_numeric($excluded['real_value'])) {
                $value = (int) $excluded['real_value'];
                $usedExcluded = true;
            }
        }

        if ($rate === null && isset($block['rate']) && is_numeric($block['rate'])) {
            $rate = (float) $block['rate'];
        }
        if ($value === null && isset($block['value']) && is_numeric($block['value'])) {
            $value = (int) $block['value'];
        }

        return [
            'rate' => $rate,
            'value' => $value,
            'used_excluded' => $usedExcluded,
        ];
    }

    /**
     * @param  array{leader?: float, green?: float, yellow?: float, orange?: float}  $thresholds
     */
    private function bandForRate(float $rate, array $thresholds): string
    {
        foreach (['leader', 'green', 'yellow', 'orange'] as $band) {
            if (isset($thresholds[$band]) && is_numeric($thresholds[$band]) && $rate <= (float) $thresholds[$band]) {
                return $band;
            }
        }

        return 'red';
    }

    /**
     * @param  list<string>  $bands
     */
    private function worstBand(array $bands): ?string
    {
        $worst = null;
        $worstRank = -1;
        foreach ($bands as $band) {
            $rank = array_search($band, self::BAND_ORDER, true);
            if ($rank === false) {
                continue;
            }
            if ($rank > $worstRank) {
                $worstRank = $rank;
                $worst = $band;
            }
        }

        return $worst;
    }

    public function bandRank(string $band): int
    {
        $rank = array_search($band, self::BAND_ORDER, true);

        return $rank === false ? -1 : $rank;
    }
}
