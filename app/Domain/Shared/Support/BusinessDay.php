<?php

namespace App\Domain\Shared\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Calendar-day helpers in the configured business timezone (default Mexico City).
 */
final class BusinessDay
{
    public static function timezone(): string
    {
        return (string) config('app.business_timezone', 'America/Mexico_City');
    }

    public static function now(): Carbon
    {
        return now(self::timezone());
    }

    public static function today(): Carbon
    {
        return self::now()->startOfDay();
    }

    /**
     * Inclusive local calendar date → half-open UTC range [start, end).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function utcRangeForDate(Carbon|string $date): array
    {
        $startLocal = $date instanceof Carbon
            ? $date->copy()->timezone(self::timezone())->startOfDay()
            : Carbon::parse($date, self::timezone())->startOfDay();

        $startUtc = $startLocal->copy()->utc();
        $endUtc = $startLocal->copy()->addDay()->utc();

        return [$startUtc, $endUtc];
    }

    /**
     * Inclusive from/to calendar dates (Y-m-d) → half-open UTC range.
     *
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    public static function utcRangeForDateStrings(?string $from, ?string $to): array
    {
        $start = null;
        $end = null;

        if (is_string($from) && $from !== '') {
            [$start] = self::utcRangeForDate($from);
        }

        if (is_string($to) && $to !== '') {
            [, $end] = self::utcRangeForDate($to);
        }

        return [$start, $end];
    }

    /**
     * SQL expression that yields the business calendar date (Y-m-d) for a UTC datetime column.
     */
    public static function dateSql(string $column): string
    {
        $tz = self::timezone();
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            // Mexico City is UTC-6 year-round (no DST since 2022). Good enough for tests.
            return "date({$column}, '-6 hours')";
        }

        $escaped = str_replace("'", "''", $tz);

        return "DATE(CONVERT_TZ({$column}, '+00:00', '{$escaped}'))";
    }
}
