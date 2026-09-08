<?php

namespace App\Jobs\Returns;

use App\Domain\Returns\Actions\AggregateProductReturnNarrative;
use App\Jobs\Concerns\TenantAwareJob;

final class AggregateProductReturnNarrativeJob extends TenantAwareJob
{
    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly ?int $productId,
        public readonly ?string $mlItemId,
        public readonly string $periodPreset = 'last_30_days',
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('normal-sync');
    }

    protected function handleForTenant(): void
    {
        app(AggregateProductReturnNarrative::class)->execute(
            $this->workspaceId,
            $this->productId,
            $this->mlItemId,
            $this->periodPreset,
        );
    }
}
