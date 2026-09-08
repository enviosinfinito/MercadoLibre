<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Cash\Actions\SyncMarketplacePaymentForOrder;
use App\Domain\PostSale\Actions\ResolveOrderPostSaleOutcome;
use App\Models\FinancialEvent;
use App\Models\MarketplacePayment;
use App\Models\Order;
use App\Models\ProfitSnapshot;
use App\Models\RawResourceSnapshot;

/**
 * Ensures expected financial events + profit snapshot match current finance rules
 * (typed fees + tax retention). Safe to call repeatedly (idempotent events + FIFO).
 */
final class RefreshExpectedOrderFinance
{
    public function __construct(
        private readonly RecordExpectedFinancialEvents $recordExpectedFinancialEvents,
        private readonly SyncExpectedClaimRefund $syncExpectedClaimRefund,
        private readonly CalculateExpectedProfit $calculateExpectedProfit,
        private readonly ResolveOrderPostSaleOutcome $resolveOrderPostSaleOutcome,
    ) {}

    public function execute(Order $order): ProfitSnapshot
    {
        $outcome = $this->resolveOrderPostSaleOutcome->apply($order);

        // Full claim reversal: do not re-seed fees/taxes; SyncExpectedClaimRefund owns that stage.
        if (! $this->resolveOrderPostSaleOutcome->isFullReversal($outcome)) {
            $this->recordExpectedFinancialEvents->execute($order);
        }

        $this->syncExpectedClaimRefund->execute($order->fresh(['lines']) ?? $order);

        $snapshot = $this->calculateExpectedProfit->execute($order->fresh(['lines']) ?? $order);

        try {
            app(SyncMarketplacePaymentForOrder::class)->execute($order->fresh() ?? $order, allowFetch: true);
        } catch (\Throwable) {
            // Cash sync is best-effort; P&L already persisted.
        }

        return $snapshot;
    }

    public function needsRefresh(Order $order): bool
    {
        $resolvedOutcome = $this->resolveOrderPostSaleOutcome->resolve($order);
        if ($resolvedOutcome !== $order->post_sale_outcome) {
            return true;
        }

        $events = FinancialEvent::query()
            ->where('order_id', $order->id)
            ->where('stage', 'expected')
            ->get(['event_type', 'provenance', 'amount']);

        $types = $events->pluck('event_type');

        if ($this->resolveOrderPostSaleOutcome->isFullReversal($resolvedOutcome)) {
            if (! $types->contains('expected_refund')) {
                return true;
            }
            foreach (['expected_fee_sale', 'expected_fee', 'expected_tax_isr', 'expected_tax_iva', 'expected_shipping_cost'] as $feeType) {
                if ($types->contains($feeType)) {
                    return true;
                }
            }

            $snap = ProfitSnapshot::query()
                ->where('order_id', $order->id)
                ->where('stage', 'expected')
                ->first();

            return $snap === null;
        }

        $hasTax = $types->contains('expected_tax_isr') || $types->contains('expected_tax_iva');
        if (! $hasTax) {
            return true;
        }

        $snap = ProfitSnapshot::query()
            ->where('order_id', $order->id)
            ->where('stage', 'expected')
            ->first();

        if ($snap === null) {
            return true;
        }

        $payload = is_array($snap->payload) ? $snap->payload : [];
        if (! isset($payload['breakdown']) || ! isset($payload['taxes_retention_total'])) {
            return true;
        }

        if (! isset($payload['marketplace_net_amount']) && ! isset($payload['net_received_amount'])) {
            return true;
        }

        // Upgrade path: legacy flat fee still present while ML sale_fee is available.
        if ($types->contains('expected_fee') && ! $types->contains('expected_fee_sale')) {
            if ($this->rawHasSaleFee($order)) {
                return true;
            }
        }

        // Shipping id present but seller shipping cost not yet recorded as a fee line.
        if (! $types->contains('expected_shipping_cost') && $this->hasShippingId($order)) {
            return true;
        }

        // Outdated shipping (base_cost) or taxes (old estimate on gross).
        foreach ($events as $event) {
            $source = is_array($event->provenance) ? ($event->provenance['source'] ?? null) : null;
            if ($event->event_type === 'expected_shipping_cost'
                && in_array($source, ['mercadolibre_shipment_base_cost', null], true)
                && $this->hasShippingId($order)) {
                return true;
            }
            if (in_array($event->event_type, ['expected_tax_isr', 'expected_tax_iva', 'expected_tax_retention'], true)
                && ! in_array($source, [
                    'mercadolibre_collections_residual',
                    'mercadolibre_collections_residual_split',
                ], true)
                && $this->hasShippingId($order)) {
                // Upgrade estimates to collections residual when possible.
                return true;
            }
        }

        // Meta still missing costs-api seller_cost while shipping id exists.
        $sellerCostSource = $order->meta['shipping']['seller_cost_source'] ?? null;
        if ($this->hasShippingId($order) && $sellerCostSource !== 'mercadolibre_shipment_costs_sender') {
            return true;
        }

        if ($this->splitPaymentsNeedRefresh($order, $payload)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function splitPaymentsNeedRefresh(Order $order, array $payload): bool
    {
        $sumNet = MarketplacePayment::query()
            ->where('order_id', $order->id)
            ->sum('net_received_amount');
        $paymentCount = MarketplacePayment::query()
            ->where('order_id', $order->id)
            ->count();
        if ($paymentCount < 2 || ! is_numeric($sumNet)) {
            return false;
        }

        $snapNet = null;
        foreach (['net_received_amount', 'marketplace_net_amount'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                $snapNet = bcadd((string) $payload[$key], '0', 6);
                break;
            }
        }
        if ($snapNet === null) {
            return false;
        }

        return bccomp(bcadd((string) $sumNet, '0', 6), $snapNet, 2) !== 0;
    }

    private function hasShippingId(Order $order): bool
    {
        $metaId = $order->meta['shipping']['id'] ?? null;
        if ($metaId !== null && $metaId !== '') {
            return true;
        }

        if (! $order->raw_snapshot_id) {
            return false;
        }

        $snapshot = RawResourceSnapshot::query()->find($order->raw_snapshot_id);
        $id = is_array($snapshot?->payload) ? ($snapshot->payload['shipping']['id'] ?? null) : null;

        return $id !== null && $id !== '';
    }

    private function rawHasSaleFee(Order $order): bool
    {
        $metaFees = $order->meta['line_sale_fees'] ?? null;
        if (is_array($metaFees)) {
            foreach ($metaFees as $row) {
                if (is_array($row) && isset($row['sale_fee']) && is_numeric($row['sale_fee'])
                    && bccomp((string) $row['sale_fee'], '0', 6) === 1) {
                    return true;
                }
            }
        }

        if (! $order->raw_snapshot_id) {
            return false;
        }

        $snapshot = RawResourceSnapshot::query()->find($order->raw_snapshot_id);
        if (! $snapshot || ! is_array($snapshot->payload)) {
            return false;
        }

        foreach ($snapshot->payload['order_items'] ?? [] as $item) {
            if (is_array($item) && isset($item['sale_fee']) && is_numeric($item['sale_fee'])
                && bccomp((string) $item['sale_fee'], '0', 6) === 1) {
                return true;
            }
        }

        return false;
    }
}
