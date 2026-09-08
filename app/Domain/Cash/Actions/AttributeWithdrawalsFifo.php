<?php

namespace App\Domain\Cash\Actions;

use App\Domain\Cash\Support\CashMoney;
use App\Models\CashLedgerEntry;
use App\Models\CashReconciliationLink;
use App\Models\Connection;
use App\Models\MarketplacePayment;
use Carbon\CarbonInterface;

/**
 * Attribute bank withdrawals to released marketplace payments in FIFO order.
 *
 * MP withdrawal rows are payout totals (not per-order). We allocate whole released
 * payment nets chronologically into each withdrawal until capacity is consumed.
 */
final class AttributeWithdrawalsFifo
{
    /**
     * @return array{links_created: int, withdrawals_considered: int, payments_attributed: int}
     */
    public function execute(
        Connection $connection,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): array {
        CashReconciliationLink::query()
            ->where('connection_id', $connection->id)
            ->where('match_method', 'withdrawal_fifo')
            ->delete();

        $credits = MarketplacePayment::query()
            ->where('connection_id', $connection->id)
            ->where('is_released', true)
            ->whereNotNull('net_received_amount')
            ->orderBy('money_release_at')
            ->orderBy('id')
            ->get();

        $withdrawalsQuery = CashLedgerEntry::query()
            ->where('connection_id', $connection->id)
            ->where('entry_type', 'withdrawal')
            ->orderBy('occurred_at')
            ->orderBy('id');

        if ($from !== null) {
            $withdrawalsQuery->where('occurred_at', '>=', $from);
        }
        if ($to !== null) {
            $withdrawalsQuery->where('occurred_at', '<=', $to);
        }

        $withdrawals = $withdrawalsQuery->get();

        /** @var list<array{payment: MarketplacePayment, remaining: string}> $creditQueue */
        $creditQueue = [];
        foreach ($credits as $payment) {
            $net = CashMoney::scale((string) $payment->net_received_amount);
            if ($net === null || CashMoney::cmp($net, '0') !== 1) {
                continue;
            }
            $creditQueue[] = [
                'payment' => $payment,
                'remaining' => $net,
            ];
        }

        $creditIndex = 0;
        $linksCreated = 0;
        $paymentsAttributed = 0;

        foreach ($withdrawals as $withdrawal) {
            $remainingWithdrawal = CashMoney::abs(
                CashMoney::scale((string) $withdrawal->net_amount) ?? CashMoney::zero(),
            );
            if (CashMoney::cmp($remainingWithdrawal, '0') !== 1) {
                continue;
            }

            while (
                CashMoney::cmp($remainingWithdrawal, '0') === 1
                && $creditIndex < count($creditQueue)
            ) {
                $payment = $creditQueue[$creditIndex]['payment'];
                $creditRemaining = $creditQueue[$creditIndex]['remaining'];

                if (CashMoney::cmp($creditRemaining, '0') !== 1) {
                    $creditIndex++;
                    continue;
                }

                $releaseAt = $payment->money_release_at;
                if (
                    $releaseAt !== null
                    && $withdrawal->occurred_at !== null
                    && $withdrawal->occurred_at->lt($releaseAt)
                ) {
                    break;
                }

                // Whole-payment FIFO: payment must fit in remaining withdrawal capacity.
                if (CashMoney::cmp($creditRemaining, $remainingWithdrawal) === 1) {
                    break;
                }

                $allocate = $creditRemaining;
                $expected = CashMoney::scale((string) ($payment->expected_net_amount ?? $payment->net_received_amount ?? '0'))
                    ?? $allocate;
                $diff = CashMoney::sub($expected, $allocate);
                $status = CashMoney::statusFromDiff($diff);

                CashReconciliationLink::query()->updateOrCreate(
                    [
                        'cash_ledger_entry_id' => $withdrawal->id,
                        'order_id' => $payment->order_id,
                        'marketplace_payment_id' => $payment->id,
                    ],
                    [
                        'workspace_id' => $connection->workspace_id,
                        'connection_id' => $connection->id,
                        'allocated_amount' => $allocate,
                        'expected_amount' => $expected,
                        'diff_amount' => $diff,
                        'match_method' => 'withdrawal_fifo',
                        'status' => $status,
                        'meta' => [
                            'attributed_at' => now()->toIso8601String(),
                            'withdrawal_external_source_id' => $withdrawal->external_source_id,
                            'note' => 'Atribución FIFO (MP no reporta payout por orden)',
                        ],
                    ],
                );

                $linksCreated++;
                $paymentsAttributed++;
                $remainingWithdrawal = CashMoney::sub($remainingWithdrawal, $allocate);
                $creditQueue[$creditIndex]['remaining'] = CashMoney::zero();
                $creditIndex++;
            }
        }

        return [
            'links_created' => $linksCreated,
            'withdrawals_considered' => $withdrawals->count(),
            'payments_attributed' => $paymentsAttributed,
        ];
    }
}
