<?php

namespace App\Domain\PostSale\Actions;

use App\Domain\Returns\Actions\ProjectReturnCaseFromClaim;
use App\Models\Claim;
use App\Models\Order;

/**
 * When an order is upserted, attach claims that reference it by resource_external_id
 * but were stored before the order existed (common during bootstrap).
 */
final class LinkOrphanClaimsToOrder
{
    public function __construct(
        private readonly ResolveOrderPostSaleOutcome $resolveOutcome,
        private readonly ProjectReturnCaseFromClaim $projectReturnCase,
    ) {}

    /**
     * @return int Number of claims linked
     */
    public function execute(Order $order, bool $projectReturns = true): int
    {
        if ($order->external_order_id === null || $order->external_order_id === '') {
            return 0;
        }

        $updated = Claim::query()
            ->where('workspace_id', $order->workspace_id)
            ->where('connection_id', $order->connection_id)
            ->whereNull('order_id')
            ->where('resource', 'order')
            ->where('resource_external_id', $order->external_order_id)
            ->update(['order_id' => $order->id]);

        if ($updated === 0) {
            return 0;
        }

        $this->resolveOutcome->apply($order);

        if ($projectReturns) {
            $order->loadMissing('lines.variant');
            $this->projectReturnCase->executeForOrder($order->fresh(['lines.variant']) ?? $order);
        }

        return $updated;
    }
}
