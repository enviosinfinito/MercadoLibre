<?php

namespace App\Domain\Cash\Actions;

use App\Domain\Cash\Support\CashMoney;
use App\Domain\Cash\Support\CashMovementConcept;
use App\Domain\Cash\Support\CashSettlementBuckets;
use App\Models\CashLedgerEntry;
use App\Models\MarketplacePayment;
use App\Models\Order;
use App\Models\ProfitSnapshot;
use Illuminate\Support\Collection;

/**
 * Build Esperado | Real | Diff rows for an order (and optional payment/ledger context).
 *
 * @return array{
 *   status: string,
 *   currency: string,
 *   banner_diff: string,
 *   rows: list<array{concept: string, expected: string|null, actual: string|null, diff: string|null, provenance: string|null}>,
 *   payments: list<array<string, mixed>>,
 *   ledger_entries: list<array<string, mixed>>
 * }
 */
final class BuildOrderCashDiff
{
    /**
     * @return array<string, mixed>
     */
    public function execute(Order $order): array
    {
        $snapshot = ProfitSnapshot::query()
            ->where('order_id', $order->id)
            ->where('stage', 'expected')
            ->first();

        $payload = is_array($snapshot?->payload) ? $snapshot->payload : [];
        $breakdown = is_array($payload['breakdown'] ?? null) ? $payload['breakdown'] : [];
        $feeLines = is_array($breakdown['fees'] ?? null) ? $breakdown['fees'] : [];
        $currency = (string) ($snapshot?->currency_code ?? $order->currency_code ?? 'MXN');

        $expectedShipping = CashSettlementBuckets::expectedShippingFromFees($feeLines);
        $expectedFee = CashSettlementBuckets::expectedFeeExcludingShipping($feeLines);
        if ($expectedFee === null) {
            $expectedFee = CashMoney::scale((string) ($snapshot?->fees_amount ?? '0'));
            if ($expectedShipping !== null && $expectedFee !== null) {
                $withoutShipping = CashMoney::sub($expectedFee, $expectedShipping);
                if (CashMoney::cmp($withoutShipping, '0') !== -1) {
                    $expectedFee = $withoutShipping;
                }
            }
        }

        $expectedTax = $this->sumBreakdownAmounts($breakdown['taxes_retention'] ?? null)
            ?? CashMoney::scale(isset($payload['taxes_retention_total']) ? (string) $payload['taxes_retention_total'] : null);
        $expectedNet = null;
        foreach (['net_received_amount', 'marketplace_net_amount'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                $expectedNet = CashMoney::scale((string) $payload[$key]);
                break;
            }
        }
        $expectedNet ??= CashMoney::scale(isset($breakdown['marketplace_net']) ? (string) $breakdown['marketplace_net'] : null);
        $expectedRevenue = CashMoney::scale((string) ($snapshot?->revenue_amount ?? $order->total_amount ?? '0'));

        $payments = MarketplacePayment::query()
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->get();

        $actualFee = CashMoney::zero();
        $actualTax = CashMoney::zero();
        $actualShipping = CashMoney::zero();
        $actualNet = CashMoney::zero();
        $actualTransaction = CashMoney::zero();
        $hasActual = false;
        $provenance = null;

        foreach ($payments as $payment) {
            $hasActual = true;
            if ($payment->marketplace_fee_amount !== null) {
                $actualFee = CashMoney::add($actualFee, (string) $payment->marketplace_fee_amount);
            }
            if ($payment->net_received_amount !== null) {
                $actualNet = CashMoney::add($actualNet, (string) $payment->net_received_amount);
            }
            if ($payment->transaction_amount !== null) {
                $actualTransaction = CashMoney::add($actualTransaction, (string) $payment->transaction_amount);
            }

            $collectionShipping = $payment->shipping_cost_amount !== null
                ? (string) $payment->shipping_cost_amount
                : null;
            $scaledCollection = CashMoney::scale($collectionShipping);
            if ($scaledCollection !== null && CashMoney::cmp($scaledCollection, '0') === 1) {
                $actualShipping = CashMoney::add($actualShipping, $scaledCollection);
            }

            if ($payment->tax_amount !== null) {
                $actualTax = CashMoney::add($actualTax, (string) $payment->tax_amount);
            }

            $provenance = is_array($payment->provenance)
                ? (string) ($payment->provenance['source'] ?? 'marketplace_payment')
                : 'marketplace_payment';
        }

        if (
            $hasActual
            && $expectedShipping !== null
            && CashMoney::cmp($expectedShipping, '0') === 1
            && CashMoney::cmp($actualShipping, '0') !== 1
        ) {
            $actualShipping = $expectedShipping;
            $actualTax = CashSettlementBuckets::residualTax(
                $actualTransaction,
                $actualFee,
                $actualShipping,
                $actualNet,
            ) ?? $actualTax;
        }

        $paymentIds = $payments->pluck('external_payment_id')->filter()->values()->all();
        $shippingIds = $this->shippingIdsForOrder($order);
        $ledger = CashLedgerEntry::query()
            ->where('connection_id', $order->connection_id)
            ->where(function ($q) use ($order, $paymentIds, $shippingIds) {
                $q->where('external_order_id', $order->external_order_id);
                if ($paymentIds !== []) {
                    $q->orWhereIn('external_source_id', $paymentIds);
                }
                if ($shippingIds !== []) {
                    $q->orWhereIn('external_order_id', $shippingIds)
                        ->orWhereIn('external_shipping_id', $shippingIds)
                        ->orWhereIn('external_reference', $shippingIds)
                        ->orWhereIn('external_source_id', $shippingIds);
                }
            })
            ->orderByDesc('occurred_at')
            ->limit(80)
            ->get();

        if ($payments->isEmpty()) {
            foreach ($ledger as $entry) {
                if ($entry->entry_type !== 'settlement') {
                    continue;
                }
                $hasActual = true;
                if ($entry->fee_amount !== null) {
                    $actualFee = CashMoney::scale((string) $entry->fee_amount) ?? $actualFee;
                }
                if ($entry->tax_amount !== null) {
                    $actualTax = CashMoney::scale((string) $entry->tax_amount) ?? $actualTax;
                }
                if ($entry->shipping_fee_amount !== null) {
                    $actualShipping = CashMoney::scale((string) $entry->shipping_fee_amount) ?? $actualShipping;
                }
                if ($entry->net_amount !== null) {
                    $actualNet = CashMoney::scale((string) $entry->net_amount) ?? $actualNet;
                }
                $provenance = (string) $entry->provenance;
            }

            if (
                $hasActual
                && $expectedShipping !== null
                && CashMoney::cmp($expectedShipping, '0') === 1
                && CashMoney::cmp($actualShipping, '0') !== 1
            ) {
                $actualShipping = $expectedShipping;
                $tx = CashMoney::scale((string) ($order->total_amount ?? '0'));
                if ($tx !== null) {
                    $actualTax = CashSettlementBuckets::residualTax(
                        $tx,
                        $actualFee,
                        $actualShipping,
                        $actualNet,
                    ) ?? $actualTax;
                }
            }
        }

        $shippingCredit = $this->sumShippingCredit($ledger);
        $hasShippingCredit = CashMoney::cmp($shippingCredit, '0') === 1;
        $holds = $this->sumHolds($ledger);
        $hasHold = CashMoney::cmp($holds, '0') !== 0;
        $walletTotal = $hasActual
            ? CashMoney::add(
                CashMoney::add($actualNet, $hasShippingCredit ? $shippingCredit : CashMoney::zero()),
                $holds,
            )
            : null;

        $rows = [
            $this->row('Ingreso', $expectedRevenue, $hasActual ? $actualTransaction : null, 'profit_snapshot'),
            $this->row('Comisión', $expectedFee, $hasActual ? $actualFee : null, $provenance),
            $this->row('Envío (seller)', $expectedShipping, $hasActual ? $actualShipping : null, $provenance, 'Lo que te cobran a ti de envío. El $ del comprador va aparte.'),
            $this->row('Impuestos / retención', $expectedTax, $hasActual ? $actualTax : null, $provenance),
            $this->row('Neto cobrado', $expectedNet, $hasActual ? $actualNet : null, $provenance),
            $this->row(
                'Envío que pagó el comprador',
                null,
                $hasActual ? $shippingCredit : null,
                $hasShippingCredit ? 'mp_release_report' : $provenance,
                CashMovementConcept::hint(CashMovementConcept::SHIPPING_CREDIT),
            ),
        ];
        if ($hasHold || ($hasActual && $payments->contains(fn (MarketplacePayment $p) => $p->status === 'in_mediation'))) {
            $rows[] = $this->row(
                'Reserva / reclamo',
                null,
                $hasActual ? $holds : null,
                'mp_release_report',
                CashMovementConcept::hint(CashMovementConcept::DISPUTE),
            );
        }
        $rows[] = $this->row(
            'Total en saldo MP',
            null,
            $walletTotal,
            $hasShippingCredit || $hasHold ? 'mp_release_report' : $provenance,
            'Cobro + envío del comprador − reservas/reembolsos.',
        );

        $bannerDiff = null;
        if ($expectedNet !== null && $hasActual) {
            $bannerDiff = CashMoney::sub($expectedNet, $actualNet);
        }

        $inMediation = $payments->contains(fn (MarketplacePayment $p) => $p->status === 'in_mediation');
        $status = 'incomplete';
        if ($inMediation || CashMoney::cmp($holds, '0') === -1) {
            $status = 'reserved';
        } elseif ($bannerDiff !== null) {
            $status = CashMoney::statusFromDiff($bannerDiff);
        } elseif ($payments->isNotEmpty()) {
            $status = (string) ($payments->first()->reconciliation_status ?? 'pending');
        }

        return [
            'status' => $status,
            'currency' => $currency,
            'banner_diff' => $bannerDiff ?? CashMoney::zero(),
            'wallet_total' => $walletTotal,
            'has_shipping_credit' => $hasShippingCredit,
            'reserved' => $status === 'reserved',
            'rows' => $rows,
            'payments' => $payments->map(fn (MarketplacePayment $p) => [
                'id' => $p->id,
                'external_payment_id' => $p->external_payment_id,
                'status' => $p->status,
                'net_received_amount' => $p->net_received_amount,
                'marketplace_fee_amount' => $p->marketplace_fee_amount,
                'shipping_cost_amount' => $p->shipping_cost_amount,
                'tax_amount' => $p->tax_amount,
                'reconciliation_status' => $p->reconciliation_status,
                'diff_amount' => $p->diff_amount,
                'is_released' => $p->is_released,
                'money_release_at' => $p->money_release_at?->toIso8601String(),
                'paid_at' => $p->paid_at?->toIso8601String(),
            ])->values()->all(),
            'ledger_entries' => $ledger->map(function (CashLedgerEntry $e) {
                $concept = CashMovementConcept::describe(
                    $e->transaction_type,
                    $e->entry_type,
                    $e->external_order_id ?? $e->external_reference,
                    $e->net_amount !== null ? (string) $e->net_amount : null,
                );

                return [
                    'id' => $e->id,
                    'entry_type' => $e->entry_type,
                    'transaction_type' => $e->transaction_type,
                    'concept' => $concept['key'],
                    'concept_label' => $concept['label'],
                    'external_source_id' => $e->external_source_id,
                    'net_amount' => $e->net_amount,
                    'fee_amount' => $e->fee_amount,
                    'tax_amount' => $e->tax_amount,
                    'shipping_fee_amount' => $e->shipping_fee_amount,
                    'is_released' => $e->is_released,
                    'occurred_at' => $e->occurred_at?->toIso8601String(),
                    'released_at' => $e->released_at?->toIso8601String(),
                    'provenance' => $e->provenance,
                ];
            })->values()->all(),
        ];
    }

    /**
     * @return list<string>
     */
    private function shippingIdsForOrder(Order $order): array
    {
        $rows = CashLedgerEntry::query()
            ->where('connection_id', $order->connection_id)
            ->where(function ($q) use ($order) {
                $q->where('external_order_id', $order->external_order_id)
                    ->orWhere('external_reference', $order->external_order_id);
            })
            ->where(function ($q) {
                $q->whereIn('transaction_type', ['SHIPPING', 'SETTLEMENT_SHIPPING'])
                    ->orWhere('transaction_type', 'like', '%SHIPPING%')
                    ->orWhereNotNull('external_shipping_id');
            })
            ->limit(50)
            ->get([
                'external_shipping_id',
                'external_reference',
                'external_source_id',
                'external_order_id',
                'transaction_type',
            ]);

        $ids = [];
        foreach ($rows as $row) {
            foreach ([$row->external_shipping_id, $row->external_reference, $row->external_source_id] as $candidate) {
                if (is_string($candidate) && CashMovementConcept::looksLikeShippingId($candidate)) {
                    $ids[$candidate] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * @param  Collection<int, CashLedgerEntry>  $ledger
     */
    private function sumShippingCredit($ledger): string
    {
        $total = CashMoney::zero();
        $seen = [];
        foreach ($ledger as $entry) {
            $lookup = (string) ($entry->external_order_id ?: $entry->external_reference ?: '');
            $concept = CashMovementConcept::fromLedger($entry->transaction_type, $entry->entry_type, $lookup);
            if ($concept !== CashMovementConcept::SHIPPING_CREDIT) {
                continue;
            }
            $key = (string) ($entry->external_source_id ?: 'id:'.$entry->id);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($entry->net_amount !== null && CashMoney::cmp((string) $entry->net_amount, '0') === 1) {
                $total = CashMoney::add($total, (string) $entry->net_amount);
            }
        }

        return $total;
    }

    /**
     * @param  Collection<int, CashLedgerEntry>  $ledger
     */
    private function sumHolds($ledger): string
    {
        $total = CashMoney::zero();
        $seen = [];
        foreach ($ledger as $entry) {
            $lookup = (string) ($entry->external_order_id ?: $entry->external_reference ?: '');
            $concept = CashMovementConcept::fromLedger($entry->transaction_type, $entry->entry_type, $lookup);
            if (! in_array($concept, [
                CashMovementConcept::DISPUTE,
                CashMovementConcept::REFUND,
                CashMovementConcept::CHARGEBACK,
            ], true)) {
                continue;
            }
            $key = ((string) ($entry->external_source_id ?: 'id:'.$entry->id)).'|'.strtoupper((string) $entry->transaction_type);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($entry->net_amount !== null) {
                $total = CashMoney::add($total, (string) $entry->net_amount);
            }
        }

        return $total;
    }

    /**
     * @param  list<mixed>|null  $lines
     */
    private function sumBreakdownAmounts(?array $lines): ?string
    {
        if ($lines === null || $lines === []) {
            return null;
        }
        $total = CashMoney::zero();
        $any = false;
        foreach ($lines as $line) {
            if (! is_array($line) || ! isset($line['amount']) || ! is_numeric($line['amount'])) {
                continue;
            }
            $any = true;
            $total = CashMoney::add($total, (string) $line['amount']);
        }

        return $any ? $total : null;
    }

    /**
     * @return array{concept: string, expected: string|null, actual: string|null, diff: string|null, provenance: string|null, note: string|null}
     */
    private function row(string $concept, ?string $expected, ?string $actual, ?string $provenance, ?string $note = null): array
    {
        $diff = null;
        if ($expected !== null && $actual !== null) {
            $diff = CashMoney::sub($expected, $actual);
        }

        return [
            'concept' => $concept,
            'expected' => $expected,
            'actual' => $actual,
            'diff' => $diff,
            'provenance' => $provenance,
            'note' => $note,
        ];
    }
}
