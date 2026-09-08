<?php

namespace App\Jobs\Returns;

use App\Domain\Returns\Actions\UpdateReturnProductDailyStats;
use App\Jobs\Concerns\TenantAwareJob;
use Illuminate\Support\Carbon;

final class UpdateReturnProductStatisticsJob extends TenantAwareJob
{
    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly string $date,
        public readonly ?int $productId = null,
        public readonly ?string $mlItemId = null,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('normal-sync');
    }

    protected function handleForTenant(): void
    {
        app(UpdateReturnProductDailyStats::class)->execute(
            $this->workspaceId,
            Carbon::parse($this->date),
            $this->productId,
            $this->mlItemId,
            $this->connectionId > 0 ? $this->connectionId : null,
        );
    }
}
