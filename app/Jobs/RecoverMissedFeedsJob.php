<?php

namespace App\Jobs;

use App\Jobs\Concerns\TenantAwareJob;
use Illuminate\Support\Facades\Log;

final class RecoverMissedFeedsJob extends TenantAwareJob
{
    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly string $from,
        public readonly string $to,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('reconciliation');
    }

    protected function handleForTenant(): void
    {
        Log::info('recover.missed_feeds.reconcile_window', [
            'workspace_id' => $this->workspaceId,
            'connection_id' => $this->connectionId,
            'from' => $this->from,
            'to' => $this->to,
        ]);
    }
}
