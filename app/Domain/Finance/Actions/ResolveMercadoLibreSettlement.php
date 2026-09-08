<?php

namespace App\Domain\Finance\Actions;

use App\Integrations\Support\LoggedHttpClient;
use App\Models\Connection;
use App\Models\Order;
use App\Models\RawResourceSnapshot;
use Throwable;

/**
 * Pulls settlement figures that ML already exposes without Billing scopes:
 * - marketplace_fee / sale_fee
 * - net_received_amount (collections)
 * - tax total as residual: revenue - fee - shipping - net
 *
 * ISR/IVA line split is NOT returned by orders/collections; Billing API is required
 * and currently 403 for this app. When residual matches the MX UI formula, we may
 * attribute the residual across ISR/IVA for display.
 */
final class ResolveMercadoLibreSettlement
{
    public function __construct(
        private readonly LoggedHttpClient $http,
    ) {}

    /**
     * @return array{
     *   revenue: string,
     *   marketplace_fee: string|null,
     *   net_received: string|null,
     *   tax_total: string|null,
     *   source: string
     * }
     */
    public function execute(Order $order, string $shippingSellerCost, bool $allowFetch = true): array
    {
        $raw = $this->rawPayload($order);
        $revenue = bcadd((string) ($order->total_amount ?? ($raw['total_amount'] ?? '0')), '0', 6);

        $marketplaceFee = null;
        $netReceived = null;

        $collections = $this->fetchCollections($order, $raw, $allowFetch);
        foreach ($collections as $collection) {
            if (isset($collection['marketplace_fee']) && is_numeric($collection['marketplace_fee'])) {
                $marketplaceFee = bcadd($marketplaceFee ?? '0', (string) $collection['marketplace_fee'], 6);
            }
            if (isset($collection['net_received_amount']) && is_numeric($collection['net_received_amount'])) {
                $netReceived = bcadd($netReceived ?? '0', (string) $collection['net_received_amount'], 6);
            }
        }

        if ($marketplaceFee === null) {
            foreach ($raw['order_items'] ?? [] as $item) {
                if (is_array($item) && isset($item['sale_fee']) && is_numeric($item['sale_fee'])) {
                    $marketplaceFee = bcadd(
                        $marketplaceFee ?? '0',
                        (string) $item['sale_fee'],
                        6,
                    );
                }
            }
        }

        $taxTotal = null;
        if ($marketplaceFee !== null && $netReceived !== null) {
            $taxTotal = bcsub($revenue, $marketplaceFee, 6);
            $taxTotal = bcsub($taxTotal, $shippingSellerCost, 6);
            $taxTotal = bcsub($taxTotal, $netReceived, 6);
            if (bccomp($taxTotal, '0', 6) === -1) {
                $taxTotal = '0.000000';
            }
        }

        return [
            'revenue' => $revenue,
            'marketplace_fee' => $marketplaceFee,
            'net_received' => $netReceived,
            'tax_total' => $taxTotal,
            'source' => $netReceived !== null ? 'mercadolibre_collections' : 'order_snapshot',
        ];
    }

    /**
     * Split a real tax residual into ISR/IVA only when the MX UI formula reconciles.
     *
     * @return list<array{event_type: string, amount: string, rate: string, base_amount: string, source: string, note: string}>
     */
    public function splitTaxRetention(string $gross, string $taxTotal): array
    {
        $ivaIncluded = (string) config('finance.tax_retention.iva_included_rate', '0.16');
        $isrRate = (string) config('finance.tax_retention.isr_rate', '0.025');
        $ivaRate = (string) config('finance.tax_retention.iva_rate', '0.08');
        $includes = (bool) config('finance.tax_retention.price_includes_iva', true);

        $base = $includes
            ? bcdiv($gross, bcadd('1', $ivaIncluded, 8), 6)
            : bcadd($gross, '0', 6);

        $isr = bcmul($base, $isrRate, 6);
        $iva = bcmul($base, $ivaRate, 6);
        $formulaTotal = bcadd($isr, $iva, 6);

        if (bccomp($this->absAmount(bcsub($formulaTotal, $taxTotal, 6)), '0.02', 6) === 1) {
            return [[
                'event_type' => 'expected_tax_retention',
                'amount' => $taxTotal,
                'rate' => '',
                'base_amount' => $gross,
                'source' => 'mercadolibre_collections_residual',
                'note' => 'Impuestos/retenciones = ingreso − comisión − envío − neto cobrado (API collections)',
            ]];
        }

        if (bccomp($formulaTotal, '0', 6) === 1 && bccomp($formulaTotal, $taxTotal, 6) !== 0) {
            $isr = bcmul($taxTotal, bcdiv($isr, $formulaTotal, 8), 6);
            $iva = bcsub($taxTotal, $isr, 6);
        }

        return [
            [
                'event_type' => 'expected_tax_isr',
                'amount' => $isr,
                'rate' => $isrRate,
                'base_amount' => $base,
                'source' => 'mercadolibre_collections_residual_split',
                'note' => 'Parte del residual real de collections, etiquetada ISR (Billing API no disponible)',
            ],
            [
                'event_type' => 'expected_tax_iva',
                'amount' => $iva,
                'rate' => $ivaRate,
                'base_amount' => $base,
                'source' => 'mercadolibre_collections_residual_split',
                'note' => 'Parte del residual real de collections, etiquetada IVA (Billing API no disponible)',
            ],
        ];
    }

    private function absAmount(string $amount): string
    {
        return bccomp($amount, '0', 8) === -1 ? bcmul($amount, '-1', 8) : $amount;
    }

    /**
     * All countable collections for the order (approved / in mediation, etc.).
     *
     * @param  array<string, mixed>|null  $raw
     * @return list<array<string, mixed>>
     */
    private function fetchCollections(Order $order, ?array $raw, bool $allowFetch): array
    {
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

        $token = null;
        if ($allowFetch) {
            try {
                $connection = Connection::query()->with('credential')->find($order->connection_id);
                if ($connection !== null) {
                    $token = app(\App\Domain\Integrations\Actions\EnsureFreshConnectionToken::class)
                        ->execute($connection);
                }
            } catch (Throwable) {
                $token = null;
            }
        }

        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $collections = [];

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

            if (! is_array($collection) || ! $this->countsTowardSettlement($collection)) {
                continue;
            }

            $collections[] = $collection;
        }

        return $collections;
    }

    /**
     * @param  array<string, mixed>  $collection
     */
    private function countsTowardSettlement(array $collection): bool
    {
        $status = strtolower(trim((string) ($collection['status'] ?? '')));

        return $status === ''
            || in_array($status, ['approved', 'authorized', 'in_process', 'in_mediation'], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rawPayload(Order $order): ?array
    {
        if (! $order->raw_snapshot_id) {
            return null;
        }

        $snapshot = RawResourceSnapshot::query()->find($order->raw_snapshot_id);

        return is_array($snapshot?->payload) ? $snapshot->payload : null;
    }
}
