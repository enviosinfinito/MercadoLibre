<?php

namespace App\Jobs\Returns;

use App\Domain\Returns\Actions\ProjectReturnCaseFromClaim;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\Claim;
use App\Models\Order;

final class ProjectReturnCaseJob extends TenantAwareJob
{
    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly ?int $claimId = null,
        public readonly ?int $orderId = null,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('normal-sync');
    }

    protected function handleForTenant(): void
    {
        $projector = app(ProjectReturnCaseFromClaim::class);

        if ($this->claimId !== null) {
            $claim = Claim::query()
                ->where('workspace_id', $this->workspaceId)
                ->find($this->claimId);
            if ($claim !== null) {
                $projector->execute($claim);
            }

            return;
        }

        if ($this->orderId !== null) {
            $order = Order::query()
                ->where('workspace_id', $this->workspaceId)
                ->with('lines.variant')
                ->find($this->orderId);
            if ($order !== null) {
                $projector->executeForOrder($order);
            }
        }
    }
}
