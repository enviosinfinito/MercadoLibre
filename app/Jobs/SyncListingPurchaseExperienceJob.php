<?php

namespace App\Jobs;

use App\Domain\Catalog\Actions\SyncChannelListingPurchaseExperience;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\ChannelListing;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SyncListingPurchaseExperienceJob extends TenantAwareJob
{
    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly int $channelListingId,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('normal-sync');
    }

    protected function handleForTenant(): void
    {
        $listing = ChannelListing::query()->find($this->channelListingId);
        if ($listing === null) {
            return;
        }

        try {
            app(SyncChannelListingPurchaseExperience::class)->execute($listing);
        } catch (Throwable $e) {
            Log::warning('listing.pe.job_failed', [
                'listing_id' => $this->channelListingId,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);
        }
    }
}
