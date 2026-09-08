<?php

namespace App\Domain\PostSale\Actions;

use App\Domain\Finance\Actions\CalculateExpectedProfit;
use App\Domain\Finance\Actions\SyncExpectedClaimRefund;
use App\Domain\Inventory\Actions\RestockOrderFromReturn;
use App\Domain\Returns\Actions\ProjectReturnCaseFromClaim;
use App\Models\Order;

/**
 * After a claim upsert: denormalize post_sale_outcome, align expected P&L, restock returns.
 */
final class ApplyOrderPostSaleEffects
{
    public function __construct(
        private readonly ResolveOrderPostSaleOutcome $resolveOrderPostSaleOutcome,
        private readonly SyncExpectedClaimRefund $syncExpectedClaimRefund,
        private readonly CalculateExpectedProfit $calculateExpectedProfit,
        private readonly RestockOrderFromReturn $restockOrderFromReturn,
        private readonly ProjectReturnCaseFromClaim $projectReturnCaseFromClaim,
    ) {}

    public function execute(?int $orderId): void
    {
        if ($orderId === null) {
            return;
        }

        $order = Order::query()->with('lines')->find($orderId);
        if ($order === null) {
            return;
        }

        $this->resolveOrderPostSaleOutcome->apply($order);
        $order = $order->fresh(['lines']) ?? $order;

        $this->syncExpectedClaimRefund->execute($order);
        $order = $order->fresh(['lines']) ?? $order;

        if ($order->post_sale_outcome === ResolveOrderPostSaleOutcome::RETURNED) {
            $this->restockOrderFromReturn->execute($order);
        }

        $this->calculateExpectedProfit->execute($order->fresh(['lines']) ?? $order);

        $order = $order->fresh(['lines.variant']) ?? $order;
        $this->projectReturnCaseFromClaim->executeForOrder($order);
    }
}
