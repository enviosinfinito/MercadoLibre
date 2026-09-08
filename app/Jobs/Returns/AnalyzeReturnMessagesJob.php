<?php

namespace App\Jobs\Returns;

use App\Domain\PostSale\Actions\SyncOrderMessages;
use App\Domain\Returns\Actions\AnalyzeReturnMessagesHybrid;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\ReturnCase;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AnalyzeReturnMessagesJob extends TenantAwareJob
{
    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly int $returnId,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('normal-sync');
    }

    protected function handleForTenant(): void
    {
        $returnCase = ReturnCase::query()
            ->where('workspace_id', $this->workspaceId)
            ->with(['claim', 'order', 'messageAnalysis'])
            ->find($this->returnId);

        if ($returnCase === null) {
            return;
        }

        if ($returnCase->order !== null) {
            $hasMessages = $returnCase->order->messages()->exists();
            if (! $hasMessages) {
                try {
                    app(SyncOrderMessages::class)->execute($returnCase->order, refreshFromProvider: true);
                } catch (Throwable $e) {
                    Log::info('returns.analyze.sync_messages_failed', [
                        'return_id' => $returnCase->id,
                        'error' => mb_substr($e->getMessage(), 0, 200),
                    ]);
                }
            }
        }

        app(AnalyzeReturnMessagesHybrid::class)->execute($returnCase);
    }
}
