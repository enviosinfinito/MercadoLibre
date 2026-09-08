<?php

namespace App\Jobs;

use App\Domain\Cash\Actions\SyncMercadoPagoReport;
use App\Domain\Cash\Support\CashReportSyncProgress;
use App\Domain\Integrations\Actions\EnsureFreshConnectionToken;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\Connection;
use App\Models\ConnectionCapability;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PollMercadoPagoReportJob extends TenantAwareJob implements ShouldBeUniqueUntilProcessing
{
    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly string $reportKind,
        public readonly string $begin,
        public readonly string $end,
        public readonly ?string $reportId = null,
        public readonly int $attempt = 1,
        public readonly bool $reconcileAfter = true,
        public readonly ?string $fromIso = null,
        public readonly ?string $toIso = null,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return implode(':', [
            'mp-report-poll',
            $this->connectionId,
            $this->reportKind,
            $this->begin,
            $this->end,
        ]);
    }

    public function uniqueFor(): int
    {
        return 7200;
    }

    protected function handleForTenant(): void
    {
        $connection = Connection::query()->findOrFail($this->connectionId);
        $sync = app(SyncMercadoPagoReport::class);
        $max = $sync->pollMaxAttempts();

        try {
            CashReportSyncProgress::setReport(
                $this->workspaceId,
                $this->connectionId,
                $this->reportKind,
                'waiting_report',
                "Consultando si el reporte ya está listo (intento {$this->attempt}/{$max})…",
                [
                    'attempt' => $this->attempt,
                    'max_attempts' => $max,
                    'report_id' => $this->reportId,
                    'begin' => $this->begin,
                    'end' => $this->end,
                ],
            );

            $token = app(EnsureFreshConnectionToken::class)->execute($connection->loadMissing('credential'));
            $ready = $sync->findReadyReport(
                $this->reportKind,
                $token,
                $this->begin,
                $this->end,
                $this->reportId,
            );

            if ($ready !== null) {
                $from = $this->fromIso ? CarbonImmutable::parse($this->fromIso) : null;
                $to = $this->toIso ? CarbonImmutable::parse($this->toIso) : null;
                $sync->completeReport(
                    $connection,
                    $this->reportKind,
                    $token,
                    $ready['file_name'],
                    reconcileAfter: false,
                    from: $from,
                    to: $to,
                    reportId: $this->reportId ?? ($ready['report_id'] ?? null),
                );

                if ($this->reconcileAfter) {
                    ReconcileCashLedgerJob::dispatch(
                        $this->workspaceId,
                        $this->connectionId,
                        $this->fromIso,
                        $this->toIso,
                    );
                }

                return;
            }

            if ($this->attempt >= $max) {
                $this->markPendingTimeout($connection);
                CashReportSyncProgress::setReport(
                    $this->workspaceId,
                    $this->connectionId,
                    $this->reportKind,
                    'failed',
                    "Timeout: MP no entregó el archivo tras {$max} intentos. Vuelve a sincronizar más tarde.",
                    [
                        'attempt' => $this->attempt,
                        'max_attempts' => $max,
                        'report_id' => $this->reportId,
                        'error' => 'pending_timeout',
                    ],
                );
                Log::warning('Mercado Pago report poll timed out', [
                    'connection_id' => $this->connectionId,
                    'report_kind' => $this->reportKind,
                    'begin' => $this->begin,
                    'end' => $this->end,
                    'report_id' => $this->reportId,
                    'attempt' => $this->attempt,
                ]);

                return;
            }

            $nextAttempt = $this->attempt + 1;
            $delay = $sync->pollDelaySeconds($nextAttempt);
            CashReportSyncProgress::setReport(
                $this->workspaceId,
                $this->connectionId,
                $this->reportKind,
                'waiting_report',
                "Aún generando en MP. Próximo chequeo (#{$nextAttempt}/{$max}) en {$delay}s…",
                [
                    'attempt' => $this->attempt,
                    'max_attempts' => $max,
                    'report_id' => $this->reportId,
                    'begin' => $this->begin,
                    'end' => $this->end,
                    'next_delay_seconds' => $delay,
                ],
            );

            self::dispatch(
                $this->workspaceId,
                $this->connectionId,
                $this->reportKind,
                $this->begin,
                $this->end,
                $this->reportId,
                $nextAttempt,
                $this->reconcileAfter,
                $this->fromIso,
                $this->toIso,
            )->delay(now()->addSeconds($delay));
        } catch (Throwable $e) {
            CashReportSyncProgress::setReport(
                $this->workspaceId,
                $this->connectionId,
                $this->reportKind,
                'failed',
                'Error en poll: '.$e->getMessage(),
                ['error' => $e->getMessage(), 'attempt' => $this->attempt],
            );
            throw $e;
        }
    }

    private function markPendingTimeout(Connection $connection): void
    {
        $capabilityKey = in_array($this->reportKind, ['release', 'released_money'], true)
            ? (string) config('finance.cash.capability_keys.release_report')
            : (string) config('finance.cash.capability_keys.settlement_report');

        $capability = ConnectionCapability::query()
            ->where('connection_id', $connection->id)
            ->where('capability_key', $capabilityKey)
            ->first();

        $meta = is_array($capability?->meta) ? $capability->meta : [];
        $meta['last_sync'] = 'pending_timeout';
        $meta['last_sync_at'] = now()->toIso8601String();
        $meta['last_report_id'] = $this->reportId;

        ConnectionCapability::query()->updateOrCreate(
            ['connection_id' => $connection->id, 'capability_key' => $capabilityKey],
            [
                'workspace_id' => $connection->workspace_id,
                'enabled' => $capability?->enabled ?? true,
                'meta' => $meta,
            ],
        );
    }
}
