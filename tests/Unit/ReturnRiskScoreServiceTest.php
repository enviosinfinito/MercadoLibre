<?php

namespace Tests\Unit;

use App\Domain\Returns\Services\ReturnRiskScoreService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReturnRiskScoreServiceTest extends TestCase
{
    #[Test]
    public function caps_critical_when_sample_is_small(): void
    {
        $service = app(ReturnRiskScoreService::class);

        $result = $service->calculate([
            'return_rate' => 1.0,
            'returned_units' => 1,
            'units_sold' => 1,
            'returned_amount' => 100,
            'workspace_returned_amount' => 1000,
            'historical_rate' => 0.02,
            'recent_rate' => 1.0,
            'prior_rate' => 0.02,
            'top_reason_share' => 1.0,
            'defective_share' => 1.0,
            'max_variant_rate' => null,
            'peer_variant_rate' => null,
        ]);

        $this->assertSame('low', $result['confidence']);
        $this->assertNotSame('critical', $result['level']);
        $this->assertNotEmpty($result['factors']);
    }

    #[Test]
    public function high_volume_high_rate_scores_higher(): void
    {
        $service = app(ReturnRiskScoreService::class);

        $result = $service->calculate([
            'return_rate' => 0.15,
            'returned_units' => 40,
            'units_sold' => 250,
            'returned_amount' => 50000,
            'workspace_returned_amount' => 100000,
            'historical_rate' => 0.03,
            'recent_rate' => 0.2,
            'prior_rate' => 0.04,
            'top_reason_share' => 0.7,
            'defective_share' => 0.6,
            'max_variant_rate' => 0.3,
            'peer_variant_rate' => 0.05,
        ]);

        $this->assertGreaterThanOrEqual(60, $result['score']);
        $this->assertContains($result['level'], ['high', 'critical']);
    }
}
