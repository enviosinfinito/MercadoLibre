<?php

namespace App\Domain\Cash\Support;

use Carbon\CarbonImmutable;

final class CashReportDateWindows
{
    /**
     * Split an inclusive local-date range into chunk windows.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public static function chunk(
        CarbonImmutable $from,
        CarbonImmutable $to,
        int $chunkDays = 2,
        ?string $timezone = null,
    ): array {
        $tz = $timezone ?: (string) config('app.business_timezone', config('app.timezone', 'UTC'));
        $chunkDays = max(1, $chunkDays);
        $cursor = $from->timezone($tz)->startOfDay();
        $endDay = $to->timezone($tz)->startOfDay();
        if ($cursor->gt($endDay)) {
            [$cursor, $endDay] = [$endDay, $cursor];
        }

        $windows = [];
        while ($cursor->lte($endDay)) {
            $chunkEnd = $cursor->addDays($chunkDays - 1);
            if ($chunkEnd->gt($endDay)) {
                $chunkEnd = $endDay;
            }
            $windows[] = [$cursor->startOfDay(), $chunkEnd->endOfDay()];
            $cursor = $chunkEnd->addDay()->startOfDay();
        }

        return $windows;
    }
}
