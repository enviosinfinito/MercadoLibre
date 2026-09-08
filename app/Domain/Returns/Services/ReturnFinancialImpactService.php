<?php

namespace App\Domain\Returns\Services;

use App\Models\CostLayer;
use App\Models\FinancialEvent;
use App\Models\ReturnCase;
use App\Models\ReturnFinancialImpact;
use App\Models\Shipment;

final class ReturnFinancialImpactService
{
    public function compute(ReturnCase $returnCase): ReturnFinancialImpact
    {
        $returnCase->loadMissing(['order.lines', 'items']);
        $order = $returnCase->order;

        $saleAmount = (float) ($returnCase->returned_amount ?: ($order?->total_amount ?? 0));
        $refundAmount = $saleAmount;

        $shipping = 0.0;
        if ($order !== null) {
            $shippingEvent = FinancialEvent::query()
                ->where('order_id', $order->id)
                ->where('event_type', 'like', '%shipping%')
                ->sum('amount');
            $shipping = abs((float) $shippingEvent);

            if ($shipping === 0.0) {
                $shipment = Shipment::query()->where('order_id', $order->id)->first();
                $metaCost = $shipment?->meta['cost'] ?? $shipment?->meta['shipping_cost'] ?? null;
                if (is_numeric($metaCost)) {
                    $shipping = abs((float) $metaCost);
                }
            }
        }

        // Fees already cleared on full reversal by SyncExpectedClaimRefund — do not double-count.
        $feeNotRecovered = 0.0;

        $productCost = 0.0;
        foreach ($returnCase->items as $item) {
            if ($item->variant_id === null) {
                continue;
            }
            $unitCost = CostLayer::query()
                ->where('variant_id', $item->variant_id)
                ->orderByDesc('id')
                ->value('unit_cost_amount');
            if ($unitCost !== null) {
                $productCost += (float) $unitCost * (int) $item->quantity;
            }
        }

        $returnShipping = 0.0;
        $other = 0.0;
        $estimatedLoss = $shipping + $returnShipping + $productCost + $feeNotRecovered + $other;

        $impact = ReturnFinancialImpact::query()->updateOrCreate(
            ['return_id' => $returnCase->id],
            [
                'workspace_id' => $returnCase->workspace_id,
                'sale_amount' => $saleAmount,
                'refund_amount' => -$refundAmount,
                'shipping_cost' => -$shipping,
                'return_shipping_cost' => -$returnShipping,
                'product_cost' => -$productCost,
                'fee_not_recovered' => -$feeNotRecovered,
                'other_costs' => -$other,
                'estimated_total_loss' => -$estimatedLoss,
                'currency_code' => $returnCase->currency_code ?: 'MXN',
                'breakdown' => [
                    'note' => 'Fees de ML no se duplican si SyncExpectedClaimRefund ya los limpió.',
                    'components' => [
                        'refund' => $refundAmount,
                        'shipping' => $shipping,
                        'product_cost' => $productCost,
                    ],
                ],
            ],
        );

        $returnCase->forceFill(['estimated_loss' => $estimatedLoss])->save();

        return $impact;
    }
}
