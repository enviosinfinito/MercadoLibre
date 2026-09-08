<?php

namespace App\Domain\Sales\Support;

use App\Domain\Shared\Support\ProviderDateTime;
use Illuminate\Support\Carbon;

/**
 * Maps Mercado Libre order payload dates into UTC instants for persistence.
 */
final class OrderProviderDates
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function orderedAt(array $payload): ?Carbon
    {
        return ProviderDateTime::parseUtc($payload['date_created'] ?? null);
    }

    /**
     * Prefer payment approval; fall back to order date_closed.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function paidAt(array $payload): ?Carbon
    {
        foreach ($payload['payments'] ?? [] as $payment) {
            if (! is_array($payment)) {
                continue;
            }

            $approved = ProviderDateTime::parseUtc($payment['date_approved'] ?? null);
            if ($approved !== null) {
                return $approved;
            }
        }

        return ProviderDateTime::parseUtc($payload['date_closed'] ?? null);
    }
}
