<?php

namespace App\Jobs;

use App\Domain\Cash\Actions\SyncMercadoPagoReport;
use App\Domain\Cash\Support\CashReportSyncProgress;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\Connection;
use Carbon\CarbonImmutable;
use Throwable;

final class SyncMercadoPagoReportJob extends TenantAwareJob
{
    public int $tries = 3;

    public int $timeout = 90;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly string $reportKind = 'settlement',
        public readonly ?string $fromIso = null,
        public readonly ?string $toIso = null,
        public readonly bool $reconcileAfter = true,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('default');
    }

    protected function handleForTenant(): void
    {
        $connection = Connection::query()->findOrFail($this->connectionId);
        $from = $this->fromIso ? CarbonImmutable::parse($this->fromIso) : null;
        $to = $this->toIso ? CarbonImmutable::parse($this->toIso) : null;
        $sync = app(SyncMercadoPagoReport::class);

        try {
            CashReportSyncProgress::setReport(
                $this->workspaceId,
                $this->connectionId,
                $this->reportKind,
                'running',
                'Iniciando sync '.$this->reportKind.'…',
            );

            $request = $sync->requestReport($connection, $this->reportKind, $from, $to);

            if (($request['status'] ?? '') === 'skipped') {
                return;
            }

            if (($request['status'] ?? '') === 'ready' && is_string($request['file_name'] ?? null)) {
                $sync->completeReport(
                    $connection,
                    $this->reportKind,
                    (string) $request['token'],
                    (string) $request['file_name'],
                    reconcileAfter: false,
                    from: $request['from'] instanceof CarbonImmutable ? $request['from'] : $from,
                    to: $request['to'] instanceof CarbonImmutable ? $request['to'] : $to,
                    reportId: isset($request['report_id']) ? (string) $request['report_id'] : null,
                );

                if ($this->reconcileAfter) {
                    ReconcileCashLedgerJob::dispatch(
                        $this->workspaceId,
                        $this->connectionId,
                        ($request['from'] ?? $from)?->toIso8601String(),
                        ($request['to'] ?? $to)?->toIso8601String(),
                    );
                }

                return;
            }

            $delay = $sync->pollDelaySeconds(1);
            CashReportSyncProgress::setReport(
                $this->workspaceId,
                $this->connectionId,
                $this->reportKind,
                'waiting_report',
                "Esperando generación en MP (reintento 1/{$sync->pollMaxAttempts()} en {$delay}s)…",
                [
                    'attempt' => 1,
                    'max_attempts' => $sync->pollMaxAttempts(),
                    'report_id' => $request['report_id'] ?? null,
                    'begin' => $request['begin'] ?? null,
                    'end' => $request['end'] ?? null,
                ],
            );

            PollMercadoPagoReportJob::dispatch(
                $this->workspaceId,
                $this->connectionId,
                $this->reportKind,
                (string) $request['begin'],
                (string) $request['end'],
                isset($request['report_id']) ? (string) $request['report_id'] : null,
                1,
                $this->reconcileAfter,
                ($request['from'] ?? $from)?->toIso8601String(),
                ($request['to'] ?? $to)?->toIso8601String(),
            )->delay(now()->addSeconds($delay));
        } catch (Throwable $e) {
            CashReportSyncProgress::setReport(
                $this->workspaceId,
                $this->connectionId,
                $this->reportKind,
                'failed',
                'Error: '.$e->getMessage(),
                ['error' => $e->getMessage()],
            );
            throw $e;
        }
    }
}
