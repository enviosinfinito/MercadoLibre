<?php

namespace App\Domain\Finance\Actions;

use App\Domain\PostSale\Actions\ResolveOrderPostSaleOutcome;
use App\Models\FinancialEvent;
use App\Models\Order;
use App\Models\OrderLine;

/**
 * Align expected finance with a closed full-return / full-refund claim.
 * Partial refunds are UI-only (no structured amount on claim resolution).
 */
final class SyncExpectedClaimRefund
{
    public const PROVENANCE_SOURCE = 'claim_resolution';

    private const FEE_AND_TAX_TYPES = [
        'expected_fee',
        'expected_fee_sale',
        'expected_shipping_cost',
        'expected_advertising',
        'expected_tax_isr',
        'expected_tax_iva',
        'expected_tax_retention',
    ];

    public function __construct(
        private readonly ResolveOrderPostSaleOutcome $resolveOrderPostSaleOutcome,
    ) {}

    public function execute(Order $order): void
    {
        $order->loadMissing('lines');
        $outcome = $this->resolveOrderPostSaleOutcome->apply($order);

        if ($this->resolveOrderPostSaleOutcome->isFullReversal($outcome)) {
            $this->applyFullReversal($order, (string) $outcome);

            return;
        }

        $this->clearClaimRefunds($order);
    }

    private function applyFullReversal(Order $order, string $outcome): void
    {
        FinancialEvent::query()
            ->where('order_id', $order->id)
            ->where('stage', 'expected')
            ->whereIn('event_type', self::FEE_AND_TAX_TYPES)
            ->delete();

        $line = $order->lines->first();
        if ($line === null) {
            $this->clearClaimRefunds($order);

            return;
        }

        $gross = $this->grossLineTotal($order);
        if (bccomp($gross, '0', 6) !== 1) {
            $this->clearClaimRefunds($order);

            return;
        }

        $currency = (string) ($order->currency_code ?? $line->currency_code);
        $occurredAt = $order->cancelled_at ?? $order->paid_at ?? $order->ordered_at ?? now();
        $negative = bcmul($gross, '-1', 6);

        $existing = FinancialEvent::query()
            ->where('order_id', $order->id)
            ->where('stage', 'expected')
            ->where('event_type', 'expected_refund')
            ->where('provenance->source', self::PROVENANCE_SOURCE)
            ->first();

        $provenance = [
            'source' => self::PROVENANCE_SOURCE,
            'post_sale_outcome' => $outcome,
            'base_amount' => $gross,
            'note' => $outcome === ResolveOrderPostSaleOutcome::RETURNED
                ? 'Salida de pago por devolución'
                : 'Salida de pago (reembolso al comprador)',
        ];

        if ($existing !== null) {
            $existing->update([
                'order_line_id' => $line->id,
                'amount' => $negative,
                'reporting_amount' => $negative,
                'currency_code' => $currency,
                'reporting_currency' => $currency,
                'occurred_at' => $occurredAt,
                'provenance' => $provenance,
            ]);

            return;
        }

        FinancialEvent::query()->create([
            'workspace_id' => $order->workspace_id,
            'connection_id' => $order->connection_id,
            'order_id' => $order->id,
            'order_line_id' => $line->id,
            'event_type' => 'expected_refund',
            'stage' => 'expected',
            'amount' => $negative,
            'currency_code' => $currency,
            'reporting_amount' => $negative,
            'reporting_currency' => $currency,
            'occurred_at' => $occurredAt,
            'provenance' => $provenance,
        ]);
    }

    private function clearClaimRefunds(Order $order): void
    {
        FinancialEvent::query()
            ->where('order_id', $order->id)
            ->where('stage', 'expected')
            ->where('event_type', 'expected_refund')
            ->where('provenance->source', self::PROVENANCE_SOURCE)
            ->delete();
    }

    private function grossLineTotal(Order $order): string
    {
        $sum = '0.000000';
        foreach ($order->lines as $line) {
            /** @var OrderLine $line */
            $sum = bcadd($sum, (string) $line->line_total_amount, 6);
        }

        if (bccomp($sum, '0', 6) !== 1 && $order->total_amount !== null) {
            return number_format((float) $order->total_amount, 6, '.', '');
        }

        return $sum;
    }
}
