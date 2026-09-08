<?php

namespace App\Domain\Cash\Actions;

use App\Domain\Cash\Support\CashMoney;
use App\Domain\Integrations\Actions\EnsureFreshConnectionToken;
use App\Integrations\Support\LoggedHttpClient;
use App\Models\Connection;
use App\Models\MarketplacePayment;
use App\Models\Order;
use App\Models\ProfitSnapshot;
use App\Models\RawResourceSnapshot;
use Throwable;

/**
 * Resolve payment ids from the order snapshot, fetch collections, upsert marketplace_payments.
 *
 * @return list<MarketplacePayment>
 */
final class SyncMarketplacePaymentForOrder
{
    public function __construct(
        private readonly LoggedHttpClient $http,
        private readonly EnsureFreshConnectionToken $ensureToken,
        private readonly UpsertMarketplacePayment $upsert,
    ) {}

    /**
     * @return list<MarketplacePayment>
     */
    public function execute(Order $order, bool $allowFetch = true): array
    {
        $raw = $this->rawPayload($order);
        $paymentIds = [];
        foreach ($raw['payments'] ?? [] as $payment) {
            if (is_array($payment) && isset($payment['id'])) {
                $paymentIds[] = (string) $payment['id'];
            }
        }
        $paymentIds = array_values(array_unique($paymentIds));

        if ($paymentIds === []) {
            return [];
        }

        $connection = Connection::query()->with('credential')->find($order->connection_id);
        $token = null;
        if ($allowFetch && $connection !== null) {
            try {
                $token = $this->ensureToken->execute($connection);
            } catch (Throwable) {
                $token = null;
            }
        }

        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $payments = [];
        $applyShippingFallback = count($paymentIds) === 1;

        foreach ($paymentIds as $paymentId) {
            $collection = null;
            if ($token !== null) {
                try {
                    $response = $this->http->get($base.'/collections/'.$paymentId, [], $token);
                    if ($response->successful() && is_array($response->json())) {
                        $collection = $response->json();
                    }
                } catch (Throwable) {
                    $collection = null;
                }
            }

            if ($collection === null) {
                foreach ($raw['payments'] ?? [] as $payment) {
                    if (is_array($payment) && (string) ($payment['id'] ?? '') === $paymentId) {
                        $collection = $payment;
                        break;
                    }
                }
            }

            if (! is_array($collection)) {
                continue;
            }

            $payments[] = $this->upsert->execute(
                $order,
                $paymentId,
                $collection,
                provenance: ['triggered_by' => 'order_sync'],
                applyShippingFallback: $applyShippingFallback,
            );
        }

        $this->stampOrderReconciliation($order, $payments);

        return $payments;
    }

    /**
     * @param  list<MarketplacePayment>  $payments
     */
    private function stampOrderReconciliation(Order $order, array $payments): void
    {
        if (count($payments) <= 1) {
            return;
        }

        $sumNet = null;
        foreach ($payments as $payment) {
            if ($payment->net_received_amount === null) {
                continue;
            }
            $sumNet = $sumNet === null
                ? (string) $payment->net_received_amount
                : CashMoney::add($sumNet, (string) $payment->net_received_amount);
        }

        $expected = $this->upsertExpectedNet($order);
        if ($sumNet === null || $expected === null) {
            return;
        }

        $diff = CashMoney::sub($expected, $sumNet);
        $status = CashMoney::statusFromDiff($diff);

        foreach ($payments as $payment) {
            $payment->reconciliation_status = $status;
            $payment->diff_amount = $diff;
            $payment->save();
        }
    }

    private function upsertExpectedNet(Order $order): ?string
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

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function rawPayload(Order $order): array
    {
        if (! $order->raw_snapshot_id) {
            return [];
        }

        $snapshot = RawResourceSnapshot::query()->find($order->raw_snapshot_id);

        return is_array($snapshot?->payload) ? $snapshot->payload : [];
    }
}
