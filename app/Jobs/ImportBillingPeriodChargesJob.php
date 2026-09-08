<?php

namespace App\Jobs;

use App\Domain\Cash\Actions\ImportBillingPeriodCharges;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\Connection;

final class ImportBillingPeriodChargesJob extends TenantAwareJob
{
    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly ?string $periodKey = null,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('default');
    }

    protected function handleForTenant(): void
    {
        $connection = Connection::query()->findOrFail($this->connectionId);
        app(ImportBillingPeriodCharges::class)->execute($connection, $this->periodKey);
    }
}
