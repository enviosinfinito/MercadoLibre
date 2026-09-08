<?php

namespace App\Jobs;

use App\Domain\PostSale\Actions\SyncClaimMessages;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\Claim;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SyncClaimMessagesJob extends TenantAwareJob
{
    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly int $claimId,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('normal-sync');
    }

    protected function handleForTenant(): void
    {
        $claim = Claim::query()->find($this->claimId);
        if ($claim === null) {
            return;
        }

        try {
            app(SyncClaimMessages::class)->execute($claim, refreshFromProvider: true);
        } catch (Throwable $e) {
            Log::warning('claim.messages.sync_failed', [
                'claim_id' => $this->claimId,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);
        }
    }
}
