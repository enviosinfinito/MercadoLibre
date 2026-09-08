<?php

namespace App\Domain\Cash\Actions;

use App\Domain\Cash\Support\CashMoney;
use App\Domain\Finance\Actions\PromoteFinancialEventsToRealized;
use App\Models\CashLedgerEntry;
use App\Models\CashReconciliationLink;
use App\Models\CashReconciliationRun;
use App\Models\Connection;
use App\Models\MarketplacePayment;
use App\Models\Order;
use App\Models\ProfitSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class ReconcileCashLedger
{
    public function __construct(
        private readonly PromoteFinancialEventsToRealized $promote,
        private readonly AttributeWithdrawalsFifo $attributeWithdrawalsFifo,
    ) {}

    public function execute(
        Connection $connection,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): CashReconciliationRun {
        return DB::transaction(function () use ($connection, $from, $to) {
            $entriesQuery = CashLedgerEntry::query()
                ->where('connection_id', $connection->id)
                ->whereIn('entry_type', ['settlement', 'release', 'refund', 'chargeback', 'withdrawal'])
                ->select([
                    'id',
                    'workspace_id',
                    'connection_id',
                    'entry_type',
                    'external_source_id',
                    'external_order_id',
                    'external_reference',
                    'net_amount',
                    'occurred_at',
                    'released_at',
                    'is_released',
                ]);

            if ($from !== null) {
                $entriesQuery->where(function ($q) use ($from) {
                    $q->where('occurred_at', '>=', $from)
                        ->orWhere('released_at', '>=', $from);
                });
            }
            if ($to !== null) {
                $entriesQuery->where(function ($q) use ($to) {
                    $q->where('occurred_at', '<=', $to)
                        ->orWhere('released_at', '<=', $to);
                });
            }

            $matchedOrders = 0;
            $unmatchedEntries = 0;
            $settled = CashMoney::zero();
            $released = CashMoney::zero();
            $withdrawn = CashMoney::zero();
            $entriesConsidered = 0;

            $entriesQuery->orderBy('id')->chunkById(500, function ($entries) use (
                $connection,
                &$matchedOrders,
                &$unmatchedEntries,
                &$settled,
                &$released,
                &$withdrawn,
                &$entriesConsidered,
            ) {
                foreach ($entries as $entry) {
                    $entriesConsidered++;
                    if ($entry->entry_type === 'withdrawal') {
                        $withdrawn = CashMoney::add($withdrawn, CashMoney::abs((string) $entry->net_amount));

                        continue;
                    }

                    if (in_array($entry->entry_type, ['settlement', 'refund', 'chargeback'], true)) {
                        $settled = CashMoney::add($settled, (string) $entry->net_amount);
                    }
                    if ($entry->entry_type === 'release' || $entry->is_released === true) {
                        $released = CashMoney::add($released, (string) $entry->net_amount);
                    }

                    $match = $this->matchEntry($connection, $entry);
                    if ($match === null) {
                        $unmatchedEntries++;

                        continue;
                    }

                    [$order, $payment, $method] = $match;
                    $expected = $this->expectedNet($order, $payment);
                    $allocated = CashMoney::scale((string) $entry->net_amount) ?? CashMoney::zero();
                    $diff = $expected !== null ? CashMoney::sub($expected, $allocated) : null;
                    $status = $diff === null ? 'matched' : CashMoney::statusFromDiff($diff);

                    CashReconciliationLink::query()->updateOrCreate(
                        [
                            'cash_ledger_entry_id' => $entry->id,
                            'order_id' => $order?->id,
                            'marketplace_payment_id' => $payment?->id,
                        ],
                        [
                            'workspace_id' => $connection->workspace_id,
                            'connection_id' => $connection->id,
                            'allocated_amount' => $allocated,
                            'expected_amount' => $expected,
                            'diff_amount' => $diff,
                            'match_method' => $method,
                            'status' => $status,
                            'meta' => [
                                'reconciled_at' => now()->toIso8601String(),
                                'entry_type' => $entry->entry_type,
                            ],
                        ],
                    );

                    if ($payment !== null && $diff !== null) {
                        $multiPayment = $payment->order_id !== null
                            && MarketplacePayment::query()
                                ->where('order_id', $payment->order_id)
                                ->count() > 1;
                        if (! $multiPayment) {
                            $payment->reconciliation_status = $status;
                            $payment->expected_net_amount = $expected;
                            $payment->diff_amount = $diff;
                        }
                        if ($entry->released_at !== null) {
                            $payment->money_release_at = $entry->released_at;
                            if ($entry->is_released === null) {
                                $payment->is_released = $entry->released_at->lte(now());
                            }
                        }
                        if ($entry->is_released !== null) {
                            $payment->is_released = $entry->is_released;
                        }
                        $payment->save();
                    }

                    if ($order !== null && ($entry->is_released === true || $entry->entry_type === 'release' || $status === 'balanced')) {
                        $this->promote->execute($order);
                        $matchedOrders++;
                    } elseif ($order !== null) {
                        $matchedOrders++;
                    }
                }
            });

            $fifo = $this->attributeWithdrawalsFifo->execute($connection, $from, $to);

            $expectedTotal = $this->sumExpectedNets($connection, $from, $to);
            $diffTotal = CashMoney::sub($expectedTotal, $settled);
            $ordersUnmatched = $this->countUnmatchedOrders($connection, $from, $to);

            $runStatus = 'incomplete';
            if ($unmatchedEntries === 0 && $ordersUnmatched === 0) {
                $runStatus = CashMoney::statusFromDiff($diffTotal);
            } elseif (CashMoney::withinTolerance($diffTotal) && $unmatchedEntries === 0) {
                $runStatus = 'balanced';
            } else {
                $runStatus = CashMoney::statusFromDiff($diffTotal);
                if ($ordersUnmatched > 0 || $unmatchedEntries > 0) {
                    $runStatus = 'incomplete';
                }
            }

            return CashReconciliationRun::query()->create([
                'workspace_id' => $connection->workspace_id,
                'connection_id' => $connection->id,
                'period_from' => $from,
                'period_to' => $to,
                'expected_net_total' => $expectedTotal,
                'settled_net_total' => $settled,
                'released_net_total' => $released,
                'withdrawn_net_total' => $withdrawn,
                'diff_amount' => $diffTotal,
                'orders_matched' => $matchedOrders,
                'orders_unmatched' => $ordersUnmatched,
                'entries_unmatched' => $unmatchedEntries,
                'status' => $runStatus,
                'payload' => [
                    'entries_considered' => $entriesConsidered,
                    'withdrawal_fifo' => $fifo,
                ],
                'finished_at' => now(),
            ]);
        });
    }

    /**
     * @return array{0: ?Order, 1: ?MarketplacePayment, 2: string}|null
     */
    private function matchEntry(Connection $connection, CashLedgerEntry $entry): ?array
    {
        if ($entry->external_source_id) {
            $payment = MarketplacePayment::query()
                ->where('connection_id', $connection->id)
                ->where('external_payment_id', $entry->external_source_id)
                ->first();
            if ($payment !== null) {
                return [$payment->order, $payment, 'source_id'];
            }
        }

        $externalOrderId = $entry->external_order_id ?: $entry->external_reference;
        if ($externalOrderId) {
            $order = Order::query()
                ->where('connection_id', $connection->id)
                ->where('external_order_id', $externalOrderId)
                ->first();
            if ($order !== null) {
                $payment = MarketplacePayment::query()
                    ->where('order_id', $order->id)
                    ->orderByDesc('id')
                    ->first();

                return [$order, $payment, 'order_id'];
            }
        }

        return null;
    }

    private function expectedNet(?Order $order, ?MarketplacePayment $payment): ?string
    {
        if ($payment?->expected_net_amount !== null) {
            return CashMoney::scale((string) $payment->expected_net_amount);
        }
        if ($order === null) {
            return null;
        }

        $snapshot = ProfitSnapshot::query()
            ->where('order_id', $order->id)
            ->where('stage', 'expected')
            ->first();
        if ($snapshot === null) {
            return null;
        }
        $payload = is_array($snapshot->payload) ? $snapshot->payload : [];
        foreach (['net_received_amount', 'marketplace_net_amount'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return CashMoney::scale((string) $payload[$key]);
            }
        }

        return null;
    }

    private function sumExpectedNets(Connection $connection, ?CarbonInterface $from, ?CarbonInterface $to): string
    {
        $total = CashMoney::zero();
        $orderIds = Order::query()
            ->where('connection_id', $connection->id)
            ->when($from, fn ($q) => $q->where('paid_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('paid_at', '<=', $to))
            ->pluck('id');

        $snapshots = ProfitSnapshot::query()
            ->where('workspace_id', $connection->workspace_id)
            ->where('stage', 'expected')
            ->whereIn('order_id', $orderIds)
            ->get();

        foreach ($snapshots as $snapshot) {
            $payload = is_array($snapshot->payload) ? $snapshot->payload : [];
            $net = null;
            foreach (['net_received_amount', 'marketplace_net_amount'] as $key) {
                if (isset($payload[$key]) && is_numeric($payload[$key])) {
                    $net = CashMoney::scale((string) $payload[$key]);
                    break;
                }
            }
            if ($net !== null) {
                $total = CashMoney::add($total, $net);
            }
        }

        return $total;
    }

    private function countUnmatchedOrders(Connection $connection, ?CarbonInterface $from, ?CarbonInterface $to): int
    {
        return Order::query()
            ->where('connection_id', $connection->id)
            ->when($from, fn ($q) => $q->where('paid_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('paid_at', '<=', $to))
            ->whereDoesntHave('cashReconciliationLinks')
            ->whereDoesntHave('marketplacePayments', function ($q) {
                $q->whereIn('reconciliation_status', ['balanced', 'short', 'over']);
            })
            ->count();
    }
}
