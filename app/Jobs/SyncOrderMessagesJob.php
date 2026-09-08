<?php

namespace App\Jobs;

use App\Domain\PostSale\Actions\SyncOrderMessages;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SyncOrderMessagesJob extends TenantAwareJob
{
    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly int $orderId,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('normal-sync');
    }

    protected function handleForTenant(): void
    {
        $order = Order::query()
            ->where('workspace_id', $this->workspaceId)
            ->where('connection_id', $this->connectionId)
            ->find($this->orderId);

        if ($order === null) {
            return;
        }

        try {
            app(SyncOrderMessages::class)->execute($order, refreshFromProvider: true);
        } catch (Throwable $e) {
            Log::warning('ml.order_messages.sync_failed', [
                'order_id' => $order->id,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);
        }
    }
}
