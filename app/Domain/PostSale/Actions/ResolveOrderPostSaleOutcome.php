<?php

namespace App\Domain\PostSale\Actions;

use App\Models\Claim;
use App\Models\Order;

final class ResolveOrderPostSaleOutcome
{
    public const RETURNED = 'returned';

    public const REFUNDED = 'refunded';

    public const PARTIAL_REFUNDED = 'partial_refunded';

    public const CLAIM_OPEN = 'claim_open';

    /**
     * @return self::RETURNED|self::REFUNDED|self::PARTIAL_REFUNDED|self::CLAIM_OPEN|null
     */
    public function resolve(Order $order): ?string
    {
        $claims = Claim::query()
            ->where('workspace_id', $order->workspace_id)
            ->where('order_id', $order->id)
            ->get(['id', 'status', 'meta']);

        $hasReturned = false;
        $hasRefunded = false;
        $hasPartial = false;
        $hasOpen = false;

        foreach ($claims as $claim) {
            if ($claim->status === 'opened') {
                $hasOpen = true;
            }

            if ($claim->status !== 'closed') {
                continue;
            }

            $reason = $claim->resolutionReason();
            if ($reason === 'item_returned') {
                $hasReturned = true;
            } elseif ($reason === 'payment_refunded') {
                $hasRefunded = true;
            } elseif ($reason === 'partial_refunded') {
                $hasPartial = true;
            }
        }

        if ($hasReturned) {
            return self::RETURNED;
        }
        if ($hasRefunded) {
            return self::REFUNDED;
        }
        if ($hasPartial) {
            return self::PARTIAL_REFUNDED;
        }
        if ($hasOpen) {
            return self::CLAIM_OPEN;
        }

        return null;
    }

    /**
     * Persist denormalized outcome on the order.
     *
     * @return self::RETURNED|self::REFUNDED|self::PARTIAL_REFUNDED|self::CLAIM_OPEN|null
     */
    public function apply(Order $order): ?string
    {
        $outcome = $this->resolve($order);

        if ($order->post_sale_outcome !== $outcome) {
            $order->forceFill(['post_sale_outcome' => $outcome])->save();
        }

        return $outcome;
    }

    public function isFullReversal(?string $outcome): bool
    {
        return in_array($outcome, [self::RETURNED, self::REFUNDED], true);
    }
}
