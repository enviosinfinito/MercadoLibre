<?php

namespace App\Domain\Ads\Actions;

use App\Models\Connection;
use Illuminate\Support\Carbon;

/**
 * Compatibility wrapper: billing-period sync delegates to Product Ads metrics sync.
 */
final class SyncMercadoLibreBillingPeriod
{
    public function __construct(
        private readonly SyncMercadoLibreProductAds $syncMercadoLibreProductAds,
    ) {}

    /**
     * @param  array{period_key?: string, dry_run?: bool, date_from?: string, date_to?: string}  $options
     * @return array<string, mixed>
     */
    public function execute(Connection $connection, array $options = []): array
    {
        $periodKey = (string) ($options['period_key'] ?? now()->format('Y-m'));
        $start = Carbon::parse($periodKey.'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();
        if ($end->isFuture()) {
            $end = now()->copy()->startOfDay();
        }

        $result = $this->syncMercadoLibreProductAds->execute($connection, [
            'date_from' => $options['date_from'] ?? $start->toDateString(),
            'date_to' => $options['date_to'] ?? $end->toDateString(),
            'dry_run' => (bool) ($options['dry_run'] ?? false),
        ]);

        return array_merge($result, [
            'period_key' => $periodKey,
            'stub' => false,
        ]);
    }
}
