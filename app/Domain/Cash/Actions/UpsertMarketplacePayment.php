<?php

namespace App\Domain\Cash\Actions;

use App\Domain\Cash\Support\CashMoney;
use App\Domain\Cash\Support\CashSettlementBuckets;
use App\Models\MarketplacePayment;
use App\Models\Order;
use App\Models\ProfitSnapshot;

final class UpsertMarketplacePayment
{
    /**
     * @param  array<string, mixed>  $collection
     * @param  array<string, mixed>  $provenance
     */
    public function execute(
        Order $order,
        string $externalPaymentId,
        array $collection,
        ?int $rawSnapshotId = null,
        array $provenance = [],
        bool $applyShippingFallback = true,
    ): MarketplacePayment {
        $transaction = CashMoney::scale(
            isset($collection['transaction_amount']) ? (string) $collection['transaction_amount'] : null,
        ) ?? CashMoney::scale((string) ($order->total_amount ?? '0'));

        $fee = CashMoney::scale(
            isset($collection['marketplace_fee']) ? (string) $collection['marketplace_fee'] : null,
        );
        $net = CashMoney::scale(
            isset($collection['net_received_amount']) ? (string) $collection['net_received_amount'] : null,
        );
        $collectionShipping = CashMoney::scale(
            isset($collection['shipping_cost']) ? (string) $collection['shipping_cost'] : null,
        );

        $otherPayments = MarketplacePayment::query()
            ->where('order_id', $order->id)
            ->where('external_payment_id', '!=', $externalPaymentId)
            ->count();
        $useShippingFallback = $applyShippingFallback && $otherPayments === 0;

        $shipping = $useShippingFallback
            ? CashSettlementBuckets::resolveShippingCost(
                $collectionShipping,
                $this->expectedShippingForOrder($order),
            )
            : ($collectionShipping ?? CashMoney::zero());

        $tax = CashSettlementBuckets::residualTax($transaction, $fee, $shipping, $net);

        $expectedNet = $this->expectedNetForOrder($order);
        $diff = null;
        $status = 'pending';
        if ($otherPayments > 0) {
            $expectedNet = $net;
            $diff = $net !== null ? CashMoney::zero() : null;
            $status = $net !== null ? 'pending' : 'incomplete';
        } elseif ($net !== null && $expectedNet !== null) {
            $diff = CashMoney::sub($expectedNet, $net);
            $status = CashMoney::statusFromDiff($diff);
        } elseif ($net !== null) {
            $status = 'incomplete';
        }

        $moneyRelease = $collection['money_release_date'] ?? $collection['date_released'] ?? null;
        $isReleased = null;
        if (array_key_exists('is_released', $collection)) {
            $isReleased = filter_var($collection['is_released'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        return MarketplacePayment::query()->updateOrCreate(
            [
                'connection_id' => $order->connection_id,
                'external_payment_id' => $externalPaymentId,
            ],
            [
                'workspace_id' => $order->workspace_id,
                'order_id' => $order->id,
                'status' => isset($collection['status']) ? (string) $collection['status'] : null,
                'status_detail' => isset($collection['status_detail']) ? (string) $collection['status_detail'] : null,
                'transaction_amount' => $transaction,
                'marketplace_fee_amount' => $fee,
                'shipping_cost_amount' => $shipping,
                'tax_amount' => $tax,
                'net_received_amount' => $net,
                'currency_code' => (string) ($collection['currency_id'] ?? $order->currency_code ?? 'MXN'),
                'paid_at' => $this->parseDate($collection['date_approved'] ?? $collection['date_created'] ?? null)
                    ?? $order->paid_at,
                'money_release_at' => $this->parseDate($moneyRelease),
                'is_released' => $isReleased,
                'reconciliation_status' => $status,
                'expected_net_amount' => $expectedNet,
                'diff_amount' => $diff,
                'raw_snapshot_id' => $rawSnapshotId,
                'payload' => $collection,
                'provenance' => array_merge([
                    'source' => 'mercadolibre_collections',
                    'upserted_at' => now()->toIso8601String(),
                ], $provenance),
            ],
        );
    }

    private function expectedNetForOrder(Order $order): ?string
    {
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

        $breakdown = is_array($payload['breakdown'] ?? null) ? $payload['breakdown'] : [];
        if (isset($breakdown['marketplace_net']) && is_numeric($breakdown['marketplace_net'])) {
            return CashMoney::scale((string) $breakdown['marketplace_net']);
        }

        return null;
    }

    private function expectedShippingForOrder(Order $order): ?string
    {
        $snapshot = ProfitSnapshot::query()
            ->where('order_id', $order->id)
            ->where('stage', 'expected')
            ->first();

        if ($snapshot === null) {
            return null;
        }

        $payload = is_array($snapshot->payload) ? $snapshot->payload : [];
        $breakdown = is_array($payload['breakdown'] ?? null) ? $payload['breakdown'] : [];
        $feeLines = is_array($breakdown['fees'] ?? null) ? $breakdown['fees'] : [];

        return CashSettlementBuckets::expectedShippingFromFees($feeLines);
    }

    private function parseDate(mixed $value): ?\Carbon\CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return \Carbon\CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
