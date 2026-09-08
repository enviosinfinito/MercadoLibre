<?php

namespace Tests\Unit;

use App\Domain\Integrations\Services\EvaluateSellerReputationMetrics;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EvaluateSellerReputationMetricsTest extends TestCase
{
    #[Test]
    public function mlm_bands_follow_official_thresholds(): void
    {
        $service = new EvaluateSellerReputationMetrics;

        $result = $service->execute('MLM', [
            'claims' => ['rate' => 0.012, 'value' => 3],
            'cancellations' => ['rate' => 0.004, 'value' => 1],
            'delayed_handling_time' => ['rate' => 0.09, 'value' => 10],
        ]);

        $byMetric = collect($result['drivers'])->keyBy('metric');
        $this->assertSame('green', $byMetric['claims']['band']); // <= 1.5%
        $this->assertSame('leader', $byMetric['cancellations']['band']); // <= 0.5%
        $this->assertSame('green', $byMetric['delayed_handling_time']['band']); // <= 10%
        $this->assertSame('green', $result['worst_band']);
    }

    #[Test]
    public function uses_excluded_real_rate_when_protected(): void
    {
        $service = new EvaluateSellerReputationMetrics;

        $result = $service->execute(
            'MLM',
            [
                'claims' => [
                    'rate' => 0,
                    'value' => 0,
                    'excluded' => ['real_rate' => 0.07, 'real_value' => 12],
                ],
                'cancellations' => ['rate' => 0, 'value' => 0],
                'delayed_handling_time' => ['rate' => 0, 'value' => 0],
            ],
            realLevel: 'red',
            protectionEndDate: '2026-12-01T00:00:00.000-06:00',
        );

        $this->assertTrue($result['protected']);
        $claims = collect($result['drivers'])->firstWhere('metric', 'claims');
        $this->assertTrue($claims['used_excluded']);
        $this->assertSame(0.07, $claims['rate']);
        $this->assertSame('red', $claims['band']);
        $this->assertSame('red', $result['worst_band']);
    }
}
