<?php

namespace Tests\Unit\Shared;

use App\Domain\Sales\Support\OrderProviderDates;
use App\Domain\Shared\Support\BusinessDay;
use App\Domain\Shared\Support\ProviderDateTime;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProviderDateTimeTest extends TestCase
{
    #[Test]
    public function parse_utc_converts_offset_to_utc_instant(): void
    {
        $parsed = ProviderDateTime::parseUtc('2026-08-04T01:36:50.000-04:00');

        $this->assertNotNull($parsed);
        $this->assertSame('UTC', $parsed->timezoneName);
        $this->assertSame('2026-08-04 05:36:50', $parsed->toDateTimeString());
    }

    #[Test]
    public function order_provider_dates_prefer_payment_date_approved(): void
    {
        $payload = [
            'date_created' => '2026-08-04T01:36:50.000-04:00',
            'date_closed' => '2026-08-04T01:37:19.000-04:00',
            'payments' => [
                [
                    'id' => 1,
                    'date_approved' => '2026-08-04T01:37:00.000-04:00',
                ],
            ],
        ];

        $orderedAt = OrderProviderDates::orderedAt($payload);
        $paidAt = OrderProviderDates::paidAt($payload);

        $this->assertSame('2026-08-04 05:36:50', $orderedAt?->toDateTimeString());
        $this->assertSame('2026-08-04 05:37:00', $paidAt?->toDateTimeString());
    }

    #[Test]
    public function business_day_utc_range_uses_mexico_city_calendar(): void
    {
        config(['app.business_timezone' => 'America/Mexico_City']);

        [$start, $end] = BusinessDay::utcRangeForDate('2026-08-03');

        $this->assertSame('2026-08-03 06:00:00', $start->toDateTimeString());
        $this->assertSame('2026-08-04 06:00:00', $end->toDateTimeString());
    }
}
