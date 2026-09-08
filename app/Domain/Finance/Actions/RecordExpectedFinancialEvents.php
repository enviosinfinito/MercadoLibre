<?php

namespace App\Domain\Finance\Actions;

use App\Models\FinancialEvent;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\RawResourceSnapshot;
use Illuminate\Support\Facades\DB;

final class RecordExpectedFinancialEvents
{
    public function __construct(
        private readonly ResolveMercadoLibreShippingCost $resolveMercadoLibreShippingCost,
        private readonly ResolveMercadoLibreSettlement $resolveMercadoLibreSettlement,
    ) {}

    public function execute(Order $order): void
    {
        $order->loadMissing('lines');
        $rawPayload = $this->resolveRawPayload($order);
        $saleFeesByItem = $this->resolveSaleFeesByItem($order, $rawPayload);

        // Network I/O outside DB transaction.
        $shippingResolved = $this->resolveMercadoLibreShippingCost->execute($order, allowFetch: true);
        $shippingAmount = $shippingResolved['amount'] ?? '0.000000';
        $settlement = $this->resolveMercadoLibreSettlement->execute($order, $shippingAmount, allowFetch: true);
        $order->refresh();
        $order->loadMissing('lines');

        DB::transaction(function () use ($order, $saleFeesByItem, $shippingResolved, $settlement) {
            foreach ($order->lines as $line) {
                $base = (string) $line->line_total_amount;
                $currency = (string) $line->currency_code;
                $occurredAt = $order->ordered_at ?? now();

                $this->ensureEvent($order, $line, 'expected_revenue', $base, $currency, $occurredAt, [
                    'source' => 'canonical_order',
                    'external_order_id' => $order->external_order_id,
                    'base_amount' => $base,
                    'line_id' => $line->id,
                ]);

                $this->ensureSaleFee($order, $line, $base, $currency, $occurredAt, $saleFeesByItem, $settlement);
            }

            $this->ensureShippingCost($order, $shippingResolved);
            $this->upsertOrderTaxRetentions($order, $settlement);
        });
    }

    /**
     * @param  array{revenue: string, marketplace_fee: string|null, net_received: string|null, tax_total: string|null, source: string}  $settlement
     */
    private function upsertOrderTaxRetentions(Order $order, array $settlement): void
    {
        $line = $order->lines->first();
        if ($line === null) {
            return;
        }

        $currency = (string) ($order->currency_code ?? $line->currency_code);
        $occurredAt = $order->ordered_at ?? now();
        $gross = (string) $order->total_amount;

        FinancialEvent::query()
            ->where('order_id', $order->id)
            ->where('stage', 'expected')
            ->whereIn('event_type', ['expected_tax_isr', 'expected_tax_iva', 'expected_tax_retention'])
            ->delete();

        if ($settlement['tax_total'] !== null && bccomp($settlement['tax_total'], '0', 6) === 1) {
            $parts = $this->resolveMercadoLibreSettlement->splitTaxRetention($gross, $settlement['tax_total']);
            foreach ($parts as $part) {
                $this->createEvent(
                    $order,
                    $line,
                    $part['event_type'],
                    bcmul($part['amount'], '-1', 6),
                    $currency,
                    $occurredAt,
                    [
                        'source' => $part['source'],
                        'rate' => $part['rate'] !== '' ? $part['rate'] : null,
                        'base_amount' => $part['base_amount'],
                        'gross_amount' => $gross,
                        'net_received' => $settlement['net_received'],
                        'tax_total' => $settlement['tax_total'],
                        'line_id' => $line->id,
                        'note' => $part['note'],
                    ],
                );
            }

            return;
        }

        // Fallback when collections net is unavailable (tests / offline).
        $taxableBase = $this->taxableBaseFromGross($gross);
        $isrRate = (string) config('finance.tax_retention.isr_rate', '0.025');
        $ivaRate = (string) config('finance.tax_retention.iva_rate', '0.08');
        $isr = bcmul($taxableBase, $isrRate, 6);
        $iva = bcmul($taxableBase, $ivaRate, 6);

        $this->createEvent($order, $line, 'expected_tax_isr', bcmul($isr, '-1', 6), $currency, $occurredAt, [
            'source' => 'estimate_mx_ml_ui',
            'rate' => $isrRate,
            'gross_amount' => $gross,
            'base_amount' => $taxableBase,
            'line_id' => $line->id,
            'note' => 'Estimado: sin net_received de collections; Billing API no disponible',
        ]);
        $this->createEvent($order, $line, 'expected_tax_iva', bcmul($iva, '-1', 6), $currency, $occurredAt, [
            'source' => 'estimate_mx_ml_ui',
            'rate' => $ivaRate,
            'gross_amount' => $gross,
            'base_amount' => $taxableBase,
            'line_id' => $line->id,
            'note' => 'Estimado: sin net_received de collections; Billing API no disponible',
        ]);
    }

    /**
     * @param  array<string, string>  $saleFeesByItem
     * @param  array{marketplace_fee: string|null}  $settlement
     */
    private function ensureSaleFee(
        Order $order,
        OrderLine $line,
        string $base,
        string $currency,
        mixed $occurredAt,
        array $saleFeesByItem,
        array $settlement,
    ): void {
        if ($order->lines->count() === 1
            && $settlement['marketplace_fee'] !== null
            && bccomp($settlement['marketplace_fee'], '0', 6) === 1) {
            FinancialEvent::query()
                ->where('order_line_id', $line->id)
                ->where('stage', 'expected')
                ->whereIn('event_type', ['expected_fee', 'expected_fee_sale'])
                ->delete();

            $this->createEvent($order, $line, 'expected_fee_sale', bcmul($settlement['marketplace_fee'], '-1', 6), $currency, $occurredAt, [
                'source' => 'mercadolibre_collections_marketplace_fee',
                'base_amount' => $base,
                'line_id' => $line->id,
                'note' => 'Comisión real (marketplace_fee de collections)',
            ]);

            return;
        }

        $itemKey = $line->external_item_id !== null ? (string) $line->external_item_id : null;
        $saleFee = $itemKey !== null && array_key_exists($itemKey, $saleFeesByItem)
            ? $saleFeesByItem[$itemKey]
            : null;

        if ($saleFee !== null && bccomp($saleFee, '0', 6) === 1) {
            FinancialEvent::query()
                ->where('order_line_id', $line->id)
                ->where('stage', 'expected')
                ->whereIn('event_type', ['expected_fee', 'expected_fee_sale'])
                ->delete();

            $this->createEvent($order, $line, 'expected_fee_sale', bcmul($saleFee, '-1', 6), $currency, $occurredAt, [
                'source' => 'mercadolibre_sale_fee',
                'base_amount' => $base,
                'line_id' => $line->id,
                'note' => 'Comisión de venta reportada por Mercado Libre (sale_fee)',
            ]);

            return;
        }

        if ($this->eventExists($line->id, 'expected_fee_sale') || $this->eventExists($line->id, 'expected_fee')) {
            return;
        }

        $rate = (string) config('finance.estimated_sale_fee_rate', '0.12');
        $fee = bcmul($base, $rate, 6);

        $this->createEvent($order, $line, 'expected_fee_sale', bcmul($fee, '-1', 6), $currency, $occurredAt, [
            'source' => 'estimate',
            'rate' => $rate,
            'base_amount' => $base,
            'line_id' => $line->id,
            'note' => 'Comisión de venta estimada (sin sale_fee en snapshot)',
        ]);
    }

    private function taxableBaseFromGross(string $gross): string
    {
        $includes = (bool) config('finance.tax_retention.price_includes_iva', true);
        if (! $includes) {
            return bcadd($gross, '0', 6);
        }

        $ivaIncluded = (string) config('finance.tax_retention.iva_included_rate', '0.16');

        return bcdiv($gross, bcadd('1', $ivaIncluded, 8), 6);
    }

    /**
     * @param  array{amount: string, source: string, shipment: array<string, mixed>|null}|null  $shippingResolved
     */
    private function ensureShippingCost(Order $order, ?array $shippingResolved): void
    {
        if ($shippingResolved === null) {
            return;
        }

        $shipping = $shippingResolved['amount'];
        if (bccomp($shipping, '0', 6) !== 1) {
            return;
        }

        $existing = FinancialEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', 'expected_shipping_cost')
            ->where('stage', 'expected')
            ->first();

        $line = $order->lines->first();
        if ($line === null) {
            return;
        }

        $amount = bcmul($shipping, '-1', 6);
        $provenance = [
            'source' => $shippingResolved['source'],
            'base_amount' => $shipping,
            'line_id' => $line->id,
            'note' => 'Costo de envío del vendedor (GET /shipments/{id}/costs)',
        ];

        if ($existing) {
            $existing->update([
                'amount' => $amount,
                'reporting_amount' => $amount,
                'provenance' => $provenance,
            ]);

            return;
        }

        $this->createEvent(
            $order,
            $line,
            'expected_shipping_cost',
            $amount,
            (string) ($order->currency_code ?? $line->currency_code),
            $order->ordered_at ?? now(),
            $provenance,
        );
    }

    /**
     * @param  array<string, mixed>  $provenance
     */
    private function ensureEvent(
        Order $order,
        OrderLine $line,
        string $eventType,
        string $amount,
        string $currency,
        mixed $occurredAt,
        array $provenance,
    ): void {
        if ($this->eventExists($line->id, $eventType)) {
            return;
        }

        $this->createEvent($order, $line, $eventType, $amount, $currency, $occurredAt, $provenance);
    }

    /**
     * @param  array<string, mixed>  $provenance
     */
    private function createEvent(
        Order $order,
        OrderLine $line,
        string $eventType,
        string $amount,
        string $currency,
        mixed $occurredAt,
        array $provenance,
    ): void {
        FinancialEvent::query()->create([
            'workspace_id' => $order->workspace_id,
            'connection_id' => $order->connection_id,
            'order_id' => $order->id,
            'order_line_id' => $line->id,
            'event_type' => $eventType,
            'stage' => 'expected',
            'amount' => $amount,
            'currency_code' => $currency,
            'reporting_amount' => $amount,
            'reporting_currency' => $currency,
            'occurred_at' => $occurredAt,
            'provenance' => $provenance,
        ]);
    }

    private function eventExists(int $orderLineId, string $eventType): bool
    {
        return FinancialEvent::query()
            ->where('order_line_id', $orderLineId)
            ->where('event_type', $eventType)
            ->where('stage', 'expected')
            ->exists();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveRawPayload(Order $order): ?array
    {
        if (! $order->raw_snapshot_id) {
            return null;
        }

        $snapshot = RawResourceSnapshot::query()->find($order->raw_snapshot_id);
        if (! $snapshot || ! is_array($snapshot->payload)) {
            return null;
        }

        return $snapshot->payload;
    }

    /**
     * @param  array<string, mixed>|null  $rawPayload
     * @return array<string, string>
     */
    private function resolveSaleFeesByItem(Order $order, ?array $rawPayload): array
    {
        $fromMeta = $order->meta['line_sale_fees'] ?? null;
        if (is_array($fromMeta) && $fromMeta !== []) {
            $mapped = [];
            foreach ($fromMeta as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $itemId = isset($row['external_item_id']) ? (string) $row['external_item_id'] : null;
                if ($itemId === null || $itemId === '' || ! isset($row['sale_fee'])) {
                    continue;
                }
                $mapped[$itemId] = bcadd((string) $row['sale_fee'], '0', 6);
            }
            if ($mapped !== []) {
                return $mapped;
            }
        }

        if ($rawPayload === null) {
            return [];
        }

        $mapped = [];
        foreach ($rawPayload['order_items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $itemData = $item['item'] ?? $item;
            $itemId = isset($itemData['id']) ? (string) $itemData['id'] : null;
            if ($itemId === null || ! isset($item['sale_fee'])) {
                continue;
            }
            $mapped[$itemId] = bcadd((string) $item['sale_fee'], '0', 6);
        }

        return $mapped;
    }
}
