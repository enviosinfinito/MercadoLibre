<?php

namespace App\Jobs\Returns;

use App\Domain\Returns\Actions\ProjectReturnCaseFromClaim;
use App\Domain\Sales\Support\OrderSalesClassification;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\Order;

final class BackfillReturnCasesJob extends TenantAwareJob
{
    public function __construct(
        int $workspaceId,
        int $connectionId = 0,
        public readonly ?int $afterOrderId = null,
        public readonly int $chunkSize = 100,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('normal-sync');
    }

    protected function handleForTenant(): void
    {
        $projector = app(ProjectReturnCaseFromClaim::class);

        $query = Order::query()
            ->where('workspace_id', $this->workspaceId)
            ->whereIn('post_sale_outcome', config('returns.outcomes', OrderSalesClassification::reversedOutcomes()))
            ->orderBy('id');

        if ($this->connectionId > 0) {
            $query->where('connection_id', $this->connectionId);
        }
        if ($this->afterOrderId !== null) {
            $query->where('id', '>', $this->afterOrderId);
        }

        $orders = $query->with(['lines.variant', 'claims'])->limit($this->chunkSize)->get();
        $lastId = null;

        foreach ($orders as $order) {
            $projector->executeForOrder($order, dispatchFollowUps: true);
            $lastId = (int) $order->id;
        }

        if ($orders->count() === $this->chunkSize && $lastId !== null) {
            self::dispatch($this->workspaceId, $this->connectionId, $lastId, $this->chunkSize);
        }
    }
}
