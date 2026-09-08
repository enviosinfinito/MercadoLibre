<?php

namespace App\Jobs;

use App\Domain\Cash\Actions\ProbeCashApiCapabilities;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\Connection;

final class ProbeCashApiCapabilitiesJob extends TenantAwareJob
{
    public function __construct(int $workspaceId, int $connectionId)
    {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('default');
    }

    protected function handleForTenant(): void
    {
        $connection = Connection::query()->findOrFail($this->connectionId);
        app(ProbeCashApiCapabilities::class)->execute($connection);
    }
}
