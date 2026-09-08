<?php

namespace App\Http\Controllers;

use App\Domain\Cash\Support\CashMoney;
use App\Domain\Shared\Support\TenantContext;
use App\Models\CashLedgerEntry;
use App\Models\CashReconciliationRun;
use App\Models\ChannelListing;
use App\Models\ConnectionCapability;
use App\Models\MarketplacePayment;
use App\Models\ProfitSnapshot;
use Inertia\Inertia;
use Inertia\Response;

class FinanceController extends Controller
{
    public function dashboard(): Response
    {
        $workspaceId = (int) TenantContext::id();

        $snapshots = ProfitSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->where('stage', 'expected')
            ->get(['profit_amount', 'revenue_amount', 'fees_amount', 'cogs_amount', 'is_incomplete', 'currency_code', 'payload']);

        $currency = $snapshots->first()?->currency_code ?? 'MXN';

        $revenue = CashMoney::zero();
        $fees = CashMoney::zero();
        $cogs = CashMoney::zero();
        $profit = CashMoney::zero();
        $expectedNet = CashMoney::zero();
        foreach ($snapshots as $snapshot) {
            $revenue = CashMoney::add($revenue, (string) ($snapshot->revenue_amount ?? '0'));
            $fees = CashMoney::add($fees, (string) ($snapshot->fees_amount ?? '0'));
            $cogs = CashMoney::add($cogs, (string) ($snapshot->cogs_amount ?? '0'));
            $profit = CashMoney::add($profit, (string) ($snapshot->profit_amount ?? '0'));
            $payload = is_array($snapshot->payload) ? $snapshot->payload : [];
            foreach (['net_received_amount', 'marketplace_net_amount'] as $key) {
                if (isset($payload[$key]) && is_numeric($payload[$key])) {
                    $expectedNet = CashMoney::add($expectedNet, (string) $payload[$key]);
                    break;
                }
            }
        }

        $settled = CashMoney::zero();
        $released = CashMoney::zero();
        $withdrawn = CashMoney::zero();
        CashLedgerEntry::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('entry_type', ['settlement', 'release', 'withdrawal', 'refund', 'chargeback'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$settled, &$released, &$withdrawn) {
                foreach ($rows as $row) {
                    $net = (string) ($row->net_amount ?? '0');
                    if (in_array($row->entry_type, ['settlement', 'refund', 'chargeback'], true)) {
                        $settled = CashMoney::add($settled, $net);
                    }
                    if ($row->entry_type === 'release' || $row->is_released === true) {
                        $released = CashMoney::add($released, $net);
                    }
                    if ($row->entry_type === 'withdrawal') {
                        $withdrawn = CashMoney::add($withdrawn, $net);
                    }
                }
            });

        $diff = CashMoney::sub($expectedNet, $settled);
        $statusCounts = MarketplacePayment::query()
            ->where('workspace_id', $workspaceId)
            ->selectRaw('reconciliation_status, COUNT(*) as aggregate')
            ->groupBy('reconciliation_status')
            ->pluck('aggregate', 'reconciliation_status');

        $latestRun = CashReconciliationRun::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('id')
            ->first();

        $capabilities = ConnectionCapability::query()
            ->where('workspace_id', $workspaceId)
            ->where('capability_key', 'like', 'cash.%')
            ->get(['connection_id', 'capability_key', 'enabled', 'meta']);

        return Inertia::render('Finance/Dashboard', [
            'summary' => [
                'expected_profit' => $profit,
                'revenue' => $revenue,
                'fees' => $fees,
                'cogs' => $cogs,
                'incomplete_orders' => $snapshots->where('is_incomplete', true)->count(),
                'currency' => $currency,
                'expected_net' => $expectedNet,
                'settled_net' => $settled,
                'released_net' => $released,
                'withdrawn_net' => $withdrawn,
                'cash_diff' => $diff,
                'cash_status' => CashMoney::statusFromDiff($diff),
                'payments_short' => (int) ($statusCounts['short'] ?? 0),
                'payments_over' => (int) ($statusCounts['over'] ?? 0),
                'payments_balanced' => (int) ($statusCounts['balanced'] ?? 0),
                'payments_incomplete' => (int) (($statusCounts['incomplete'] ?? 0) + ($statusCounts['pending'] ?? 0)),
            ],
            'latest_run' => $latestRun,
            'capabilities' => $capabilities,
            'listings_without_cost' => ChannelListing::countWithoutCostForWorkspace($workspaceId),
        ]);
    }
}
