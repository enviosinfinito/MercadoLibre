<?php

namespace App\Jobs;

use App\Domain\Integrations\Actions\ResolveSyncProfile;
use App\Domain\PostSale\Actions\UpsertCanonicalQuestion;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\Connection;
use App\Models\RawResourceSnapshot;

final class ProcessCanonicalQuestionJob extends TenantAwareJob
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

        if (! $resolve->isEnabled($connection, 'questions')) {
            return;
        }

        $upsert = app(UpsertCanonicalQuestion::class);
        $mapped = $upsert->mapFromProviderPayload($payload, (string) $snapshot->external_id);

        if ($mapped['external_question_id'] === '') {
            return;
        }

        $profile = $resolve->execute($connection, 'questions');
        $include = is_array($profile->config['include'] ?? null)
            ? $profile->config['include']
            : [];

        if (($include['answer'] ?? true) === false) {
            $mapped['answer_text'] = null;
            $mapped['answered_at'] = null;
            if (is_array($mapped['meta'] ?? null)) {
                unset($mapped['meta']['answer']);
            }
        }

        $upsert->execute($this->workspaceId, $this->connectionId, [
            ...$mapped,
            'raw_snapshot_id' => $snapshot->id,
        ]);
    }
}
