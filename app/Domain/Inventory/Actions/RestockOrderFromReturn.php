<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\PostSale\Actions\ResolveOrderPostSaleOutcome;
use App\Models\CostSnapshot;
use App\Models\FullStockOperation;
use App\Models\InventoryLedger;
use App\Models\Order;
use App\Models\OrderLine;

/**
 * Re-enter inventory for physically returned lines (claim outcome = returned).
 * Idempotent per order line via receive ledger key restock:order:{id}:line:{id}.
 */
final class RestockOrderFromReturn
{
    public function __construct(
        private readonly ReceiveInventory $receiveInventory,
        private readonly ResolveFullCommerceLinks $resolveFullCommerceLinks,
    ) {}

    public static function idempotencyKey(int $orderId, int $lineId): string
    {
        return 'restock:order:'.$orderId.':line:'.$lineId;
    }

    /**
     * @return list<array{order_line_id: int, status: string, idempotency_key: string}>
     */
    public function execute(Order $order): array
    {
        if ($order->post_sale_outcome !== ResolveOrderPostSaleOutcome::RETURNED) {
            return [];
        }

        $order->loadMissing('lines');
        $results = [];

        foreach ($order->lines as $line) {
            /** @var OrderLine $line */
            if ($line->variant_id === null) {
                $results[] = [
                    'order_line_id' => (int) $line->id,
                    'status' => 'unmatched',
                    'idempotency_key' => self::idempotencyKey((int) $order->id, (int) $line->id),
                ];

                continue;
            }

            if (bccomp((string) $line->quantity, '0', 6) !== 1) {
                continue;
            }

            $key = self::idempotencyKey((int) $order->id, (int) $line->id);
            $unitCost = $this->resolveUnitCost($order, $line);

            $saleReturn = $this->linkedSaleReturn($order, (int) $line->variant_id);

            $this->receiveInventory->execute((int) $order->workspace_id, [
                'variant_id' => (int) $line->variant_id,
                'quantity' => (string) $line->quantity,
                'unit_cost_amount' => $unitCost['amount'],
                'unit_cost_currency' => $unitCost['currency'],
                'reporting_currency' => $unitCost['currency'],
                'notes' => 'Devolución orden #'.($order->external_order_id ?? $order->id).' línea '.$line->id,
                'idempotency_key' => $key,
                'received_at' => now()->toIso8601String(),
                'meta' => array_filter([
                    'order_id' => (int) $order->id,
                    'order_line_id' => (int) $line->id,
                    'full_operation_id' => $saleReturn?->id,
                ]),
            ]);

            $results[] = [
                'order_line_id' => (int) $line->id,
                'status' => 'restocked',
                'idempotency_key' => $key,
            ];
        }

        return $results;
    }

    public function lineStockStatus(Order $order, OrderLine $line): string
    {
        if ($order->post_sale_outcome === ResolveOrderPostSaleOutcome::REFUNDED
            || $order->post_sale_outcome === ResolveOrderPostSaleOutcome::PARTIAL_REFUNDED) {
            return 'not_applicable';
        }

        if ($order->post_sale_outcome !== ResolveOrderPostSaleOutcome::RETURNED) {
            return 'not_applicable';
        }

        if ($line->variant_id === null) {
            return 'unmatched';
        }

        $exists = InventoryLedger::query()
            ->where('workspace_id', $order->workspace_id)
            ->where('idempotency_key', self::idempotencyKey((int) $order->id, (int) $line->id))
            ->exists();

        return $exists ? 'restocked' : 'pending';
    }

    private function linkedSaleReturn(Order $order, int $variantId): ?FullStockOperation
    {
        $commerceIds = array_values(array_filter([
            (string) ($order->external_order_id ?? ''),
        ]));

        if ($commerceIds === []) {
            return FullStockOperation::query()
                ->where('workspace_id', $order->workspace_id)
                ->where('variant_id', $variantId)
                ->where('operation_type', 'SALE_RETURN')
                ->orderByDesc('occurred_at')
                ->first();
        }

        $ops = $this->resolveFullCommerceLinks->findOperationsForCommerceIds(
            (int) $order->workspace_id,
            $order->connection_id !== null ? (int) $order->connection_id : null,
            $commerceIds,
            50,
        );

        return $ops->first(
            fn (FullStockOperation $op) => $op->operation_type === 'SALE_RETURN'
                && (int) $op->variant_id === $variantId,
        ) ?? $ops->first(fn (FullStockOperation $op) => $op->operation_type === 'SALE_RETURN');
    }

    /**
     * @return array{amount: string, currency: string}
     */
    private function resolveUnitCost(Order $order, OrderLine $line): array
    {
        $currency = strtoupper((string) ($line->currency_code ?: $order->currency_code ?: 'MXN'));

        $snapshot = CostSnapshot::query()
            ->where('workspace_id', $order->workspace_id)
            ->where('order_line_id', $line->id)
            ->orderByDesc('id')
            ->first();

        if ($snapshot !== null) {
            $qty = (string) $line->quantity;
            $total = (string) $snapshot->total_cogs_reporting_amount;
            if (bccomp($qty, '0', 6) === 1 && bccomp($total, '0', 6) === 1) {
                return [
                    'amount' => bcdiv($total, $qty, 6),
                    'currency' => strtoupper((string) ($snapshot->currency_code ?: $currency)),
                ];
            }
        }

        return [
            'amount' => '0.000000',
            'currency' => $currency,
        ];
    }
}
