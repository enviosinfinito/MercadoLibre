<?php

namespace App\Domain\PostSale\Actions;

use App\Domain\Finance\Actions\SyncExpectedClaimRefund;
use App\Domain\Inventory\Actions\RestockOrderFromReturn;
use App\Models\Claim;
use App\Models\FinancialEvent;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Support\Collection;

final class BuildOrderPostSaleSummary
{
    public function __construct(
        private readonly RestockOrderFromReturn $restockOrderFromReturn,
    ) {}

    /**
     * @param  Collection<int, Claim>  $claims
     * @return array<string, mixed>|null
     */
    public function execute(Order $order, Collection $claims, ?Shipment $shipment = null): ?array
    {
        $outcome = $order->post_sale_outcome;
        if ($outcome === null || $outcome === '') {
            return null;
        }

        $order->loadMissing(['lines', 'financialEvents']);

        $closingClaim = $claims->first(function (Claim $claim) use ($outcome) {
            if ($claim->status !== 'closed') {
                return false;
            }
            $reason = $claim->resolutionReason();

            return ($outcome === ResolveOrderPostSaleOutcome::RETURNED && $reason === 'item_returned')
                || ($outcome === ResolveOrderPostSaleOutcome::REFUNDED && $reason === 'payment_refunded')
                || ($outcome === ResolveOrderPostSaleOutcome::PARTIAL_REFUNDED && $reason === 'partial_refunded');
        }) ?? $claims->firstWhere('status', 'closed') ?? $claims->first();

        $refundEvent = FinancialEvent::query()
            ->where('order_id', $order->id)
            ->where('stage', 'expected')
            ->where('event_type', 'expected_refund')
            ->where('provenance->source', SyncExpectedClaimRefund::PROVENANCE_SOURCE)
            ->orderByDesc('id')
            ->first();

        $money = null;
        if ($refundEvent !== null) {
            $abs = bccomp((string) $refundEvent->amount, '0', 6) === -1
                ? bcmul((string) $refundEvent->amount, '-1', 6)
                : (string) $refundEvent->amount;
            $money = [
                'status' => 'refunded',
                'amount' => $abs,
                'currency' => $refundEvent->currency_code,
                'label' => 'Salida al comprador',
                'at' => $refundEvent->occurred_at ?? $closingClaim?->closed_at,
            ];
        } elseif ($outcome === ResolveOrderPostSaleOutcome::PARTIAL_REFUNDED) {
            $money = [
                'status' => 'partial_unknown',
                'amount' => null,
                'currency' => $order->currency_code,
                'label' => 'Reembolso parcial (monto no sincronizado)',
                'at' => $closingClaim?->closed_at,
            ];
        }

        $lineRows = [];
        foreach ($order->lines as $line) {
            $status = $this->restockOrderFromReturn->lineStockStatus($order, $line);
            $lineRows[] = [
                'order_line_id' => $line->id,
                'sku' => $line->sku,
                'title' => $line->title,
                'quantity' => $line->quantity,
                'variant_id' => $line->variant_id,
                'status' => $status,
            ];
        }

        $stockStatus = $this->aggregateStockStatus($outcome, $lineRows);
        $stock = [
            'status' => $stockStatus,
            'label' => $this->stockLabel($stockStatus),
            'lines' => $lineRows,
        ];

        $paymentInbound = [
            'label' => 'Pago recibido',
            'status' => $order->paid_at !== null || in_array($order->status, ['paid', 'shipped', 'delivered'], true)
                ? 'received'
                : 'pending',
            'amount' => (string) $order->total_amount,
            'currency' => $order->currency_code,
            'at' => $order->paid_at ?? $order->ordered_at,
        ];

        $fulfillmentOutbound = null;
        if ($shipment !== null) {
            $fulfillmentOutbound = [
                'label' => 'Envío al cliente',
                'status' => $shipment->status,
                'tracking_number' => $shipment->tracking_number,
                'carrier' => $shipment->carrier,
                'shipped_at' => $shipment->shipped_at,
                'delivered_at' => $shipment->delivered_at,
            ];
        } elseif (in_array($order->status, ['shipped', 'delivered'], true)) {
            $fulfillmentOutbound = [
                'label' => 'Envío al cliente',
                'status' => $order->status,
                'tracking_number' => null,
                'carrier' => null,
                'shipped_at' => null,
                'delivered_at' => null,
            ];
        }

        $fulfillmentInbound = null;
        if ($outcome === ResolveOrderPostSaleOutcome::RETURNED) {
            $fulfillmentInbound = [
                'label' => 'Devolución del producto',
                'status' => $stockStatus,
                'status_label' => $this->stockLabel($stockStatus),
                'at' => $closingClaim?->closed_at,
                'stock' => $stock,
            ];
        } elseif (in_array($outcome, [
            ResolveOrderPostSaleOutcome::REFUNDED,
            ResolveOrderPostSaleOutcome::PARTIAL_REFUNDED,
        ], true)) {
            $fulfillmentInbound = [
                'label' => 'Sin devolución física',
                'status' => 'not_applicable',
                'status_label' => $this->stockLabel('not_applicable'),
                'at' => $closingClaim?->closed_at,
                'stock' => $stock,
            ];
        }

        return [
            'outcome' => $outcome,
            'narrative' => 'inversion',
            // Paired flows: ida → vuelta
            'payment' => [
                'inbound' => $paymentInbound,
                'outbound' => $money,
            ],
            'fulfillment' => [
                'outbound' => $fulfillmentOutbound,
                'inbound' => $fulfillmentInbound,
            ],
            // Compat / resumen plano
            'money' => $money,
            'stock' => $stock,
            'claim' => $closingClaim === null ? null : [
                'id' => $closingClaim->id,
                'external_claim_id' => $closingClaim->external_claim_id,
                'status_title' => $closingClaim->status_title,
                'status_description' => $closingClaim->status_description,
                'resolution_reason' => $closingClaim->resolutionReason(),
                'closed_at' => $closingClaim->closed_at,
            ],
        ];
    }

    /**
     * @param  list<array{status: string}>  $lineRows
     */
    private function aggregateStockStatus(?string $outcome, array $lineRows): string
    {
        if ($outcome === ResolveOrderPostSaleOutcome::REFUNDED
            || $outcome === ResolveOrderPostSaleOutcome::PARTIAL_REFUNDED) {
            return 'not_applicable';
        }

        if ($outcome !== ResolveOrderPostSaleOutcome::RETURNED) {
            return 'not_applicable';
        }

        if ($lineRows === []) {
            return 'pending';
        }

        $statuses = array_column($lineRows, 'status');
        if (in_array('unmatched', $statuses, true) && ! in_array('restocked', $statuses, true) && ! in_array('pending', $statuses, true)) {
            return 'unmatched';
        }
        if (in_array('pending', $statuses, true)) {
            return 'pending';
        }
        if (in_array('unmatched', $statuses, true)) {
            return 'unmatched';
        }
        if (in_array('restocked', $statuses, true)) {
            return 'restocked';
        }

        return 'pending';
    }

    private function stockLabel(string $status): string
    {
        return match ($status) {
            'restocked' => 'Reingresado a inventario',
            'unmatched' => 'Pendiente de match SKU',
            'pending' => 'Pendiente de reingreso',
            'not_applicable' => 'No aplica (sin devolución física)',
            default => $status,
        };
    }
}
