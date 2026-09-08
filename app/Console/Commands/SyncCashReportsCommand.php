<?php

namespace App\Console\Commands;

use App\Domain\Cash\Actions\ImportBillingPeriodCharges;
use App\Domain\Cash\Actions\ReconcileCashLedger;
use App\Domain\Cash\Actions\SyncMercadoPagoReport;
use App\Domain\Shared\Support\BusinessDay;
use App\Jobs\ImportBillingPeriodChargesJob;
use App\Jobs\ReconcileCashLedgerJob;
use App\Jobs\SyncMercadoPagoReportJob;
use App\Models\Connection;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SyncCashReportsCommand extends Command
{
    protected $signature = 'finance:sync-cash-reports
        {--connection= : Connection id}
        {--days= : Lookback days (defaults: settlement 14, release 2)}
        {--settlement : Sync account money / settlement report}
        {--release : Sync release report}
        {--billing : Import billing period charges}
        {--reconcile : Run reconciliation after ingest}
        {--sync : Run inline (short wait) instead of queue}
        {--all : Settlement + release + billing + reconcile}';

    protected $description = 'Sync Mercado Pago cash reports (async jobs by default; release polls in background)';

    public function handle(
        SyncMercadoPagoReport $syncReport,
        ImportBillingPeriodCharges $importBilling,
        ReconcileCashLedger $reconcile,
    ): int {
        $all = (bool) $this->option('all');
        $doSettlement = $all || (bool) $this->option('settlement') || (! $this->option('release') && ! $this->option('billing') && ! $this->option('reconcile'));
        $doRelease = $all || (bool) $this->option('release');
        $doBilling = $all || (bool) $this->option('billing');
        $doReconcile = $all || (bool) $this->option('reconcile');

        $tz = BusinessDay::timezone();
        $to = CarbonImmutable::now($tz)->endOfDay();
        $daysOpt = $this->option('days');
        $settlementDays = max(1, (int) ($daysOpt !== null && $daysOpt !== ''
            ? $daysOpt
            : config('finance.mercadopago.settlement_default_days', 14)));
        $releaseDays = max(1, (int) ($daysOpt !== null && $daysOpt !== ''
            ? $daysOpt
            : config('finance.mercadopago.release_default_days', 2)));

        $settlementFrom = $to->subDays($settlementDays)->startOfDay();
        $releaseFrom = $to->subDays($releaseDays)->startOfDay();

        $query = Connection::query()
            ->where('provider', 'mercadolibre')
            ->where('status', 'active')
            ->orderBy('id');
        if ($this->option('connection')) {
            $query->where('id', (int) $this->option('connection'));
        }

        foreach ($query->get() as $connection) {
            $this->info("Connection #{$connection->id}");
            if ($this->option('sync')) {
                if ($doSettlement) {
                    $r = $syncReport->execute(
                        $connection,
                        'settlement',
                        $settlementFrom,
                        $to,
                        reconcileAfter: false,
                        waitInline: true,
                    );
                    $this->line('  settlement: '.json_encode($r));
                }
                if ($doRelease) {
                    $r = $syncReport->execute(
                        $connection,
                        'release',
                        $releaseFrom,
                        $to,
                        reconcileAfter: false,
                        waitInline: true,
                    );
                    $this->line('  release: '.json_encode($r));
                }
                if ($doBilling) {
                    $r = $importBilling->execute($connection);
                    $this->line('  billing: '.json_encode($r));
                }
                if ($doReconcile) {
                    $run = $reconcile->execute($connection, $settlementFrom, $to);
                    $this->line("  reconcile: status={$run->status} diff={$run->diff_amount}");
                }
            } else {
                if ($doSettlement) {
                    SyncMercadoPagoReportJob::dispatch(
                        (int) $connection->workspace_id,
                        (int) $connection->id,
                        'settlement',
                        $settlementFrom->toIso8601String(),
                        $to->toIso8601String(),
                        reconcileAfter: ! $doReconcile,
                    );
                }
                if ($doRelease) {
                    SyncMercadoPagoReportJob::dispatch(
                        (int) $connection->workspace_id,
                        (int) $connection->id,
                        'release',
                        $releaseFrom->toIso8601String(),
                        $to->toIso8601String(),
                        reconcileAfter: ! $doReconcile,
                    );
                }
                if ($doBilling) {
                    ImportBillingPeriodChargesJob::dispatch(
                        (int) $connection->workspace_id,
                        (int) $connection->id,
                    );
                }
                if ($doReconcile) {
                    ReconcileCashLedgerJob::dispatch(
                        (int) $connection->workspace_id,
                        (int) $connection->id,
                        $settlementFrom->toIso8601String(),
                        $to->toIso8601String(),
                    );
                }
                $this->line('  jobs queued (release will poll in background if pending)');
            }
        }

        return self::SUCCESS;
    }
}
