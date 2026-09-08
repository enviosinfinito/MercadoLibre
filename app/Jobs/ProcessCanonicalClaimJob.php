<?php

namespace App\Jobs;

use App\Domain\Integrations\Actions\ResolveSyncProfile;
use App\Domain\PostSale\Actions\UpsertCanonicalClaim;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\Connection;
use App\Models\RawResourceSnapshot;

final class ProcessCanonicalClaimJob extends TenantAwareJob
{
    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly int $snapshotId,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('critical-sync');
    }

    protected function handleForTenant(): void
    {
        $snapshot = RawResourceSnapshot::query()->findOrFail($this->snapshotId);
        $payload = is_array($snapshot->payload) ? $snapshot->payload : [];
        $connection = Connection::query()->findOrFail($this->connectionId);
        $resolve = app(ResolveSyncProfile::class);

        if (! $resolve->isEnabled($connection, 'claims')) {
            return;
        }

        $upsert = app(UpsertCanonicalClaim::class);
        $mapped = $upsert->mapFromProviderPayload($payload, (string) $snapshot->external_id);

        if ($mapped['external_claim_id'] === '') {
            return;
        }

        $claim = $upsert->execute($this->workspaceId, $this->connectionId, [
            ...$mapped,
            'raw_snapshot_id' => $snapshot->id,
        ]);

        SyncClaimMessagesJob::dispatch(
            $this->workspaceId,
            $this->connectionId,
            (int) $claim->id,
        );
    }
}
