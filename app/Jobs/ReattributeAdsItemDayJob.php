<?php

namespace App\Jobs;

use App\Domain\Ads\Actions\AttributeAdvertisingToOrders;
use App\Domain\Shared\Support\BusinessDay;
use App\Jobs\Concerns\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Carbon;

/**
 * Debounced re-attribution for a single (connection, ml_item_id, business date) grain.
 */
final class ReattributeAdsItemDayJob extends TenantAwareJob implements ShouldBeUnique
{
    public int $uniqueFor = 120;

    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly string $mlItemId,
        public readonly string $date,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('normal-sync');
    }

    public function uniqueId(): string
    {
        return implode('|', [
            'reattr-ads-item-day',
            (string) $this->workspaceId,
            (string) $this->connectionId,
            $this->mlItemId,
            $this->date,
        ]);
    }

    protected function handleForTenant(): void
    {
        $date = Carbon::parse($this->date, BusinessDay::timezone())->toDateString();
        $itemId = trim($this->mlItemId);
        if ($itemId === '') {
            return;
        }

        app(AttributeAdvertisingToOrders::class)->execute($this->workspaceId, [
            'date_from' => $date,
            'date_to' => $date,
            'connection_id' => $this->connectionId,
            'ml_item_id' => $itemId,
            'refresh_profit' => true,
        ]);
    }
}
