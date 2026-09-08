<?php

namespace App\Jobs;

use App\Domain\Cash\Actions\ReconcileCashLedger;
use App\Domain\Cash\Support\CashReportSyncProgress;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\Connection;
use Carbon\CarbonImmutable;
use Throwable;

final class ReconcileCashLedgerJob extends TenantAwareJob
{
    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly ?string $fromIso = null,
        public readonly ?string $toIso = null,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('default');
    }

    protected function handleForTenant(): void
    {
        $connection = Connection::query()->findOrFail($this->connectionId);
        CashReportSyncProgress::setReconcile(
            $this->workspaceId,
            $this->connectionId,
            'running',
            'Reconciliando esperado vs real…',
        );

        try {
            $run = app(ReconcileCashLedger::class)->execute(
                $connection,
                $this->fromIso ? CarbonImmutable::parse($this->fromIso) : null,
                $this->toIso ? CarbonImmutable::parse($this->toIso) : null,
            );
            CashReportSyncProgress::setReconcile(
                $this->workspaceId,
                $this->connectionId,
                'done',
                'Reconciliación listada (status='.$run->status.', diff='.$run->diff_amount.').',
                ['status' => $run->status, 'diff_amount' => $run->diff_amount],
            );
        } catch (Throwable $e) {
            CashReportSyncProgress::setReconcile(
                $this->workspaceId,
                $this->connectionId,
                'failed',
                'Error en reconciliación: '.$e->getMessage(),
                ['error' => $e->getMessage()],
            );
            throw $e;
        }
    }
}
