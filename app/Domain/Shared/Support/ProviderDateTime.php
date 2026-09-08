<?php

namespace App\Domain\Shared\Support;

use Illuminate\Support\Carbon;

/**
 * Parse provider ISO datetimes into UTC for MySQL datetime storage.
 *
 * Eloquent formats Carbon with format('Y-m-d H:i:s') using the instance's
 * wall clock. Without ->utc(), an offset like -04:00 is persisted as if UTC.
 */
final class ProviderDateTime
{
    public static function parseUtc(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
