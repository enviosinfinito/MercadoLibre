<?php

namespace App\Domain\Sales\Support;

use App\Domain\PostSale\Actions\ResolveOrderPostSaleOutcome;
use Illuminate\Database\Eloquent\Builder;

/**
 * Canonical buckets for dashboard / alerts: successful vs cancelled vs post-sale reversals.
 */
final class OrderSalesClassification
{
    /**
     * @return list<string>
     */
    public static function cancelledStatuses(): array
    {
        return ['cancelled', 'canceled'];
    }

    /**
     * @return list<string>
     */
    public static function reversedOutcomes(): array
    {
        return [
            ResolveOrderPostSaleOutcome::RETURNED,
            ResolveOrderPostSaleOutcome::REFUNDED,
            ResolveOrderPostSaleOutcome::PARTIAL_REFUNDED,
        ];
    }

    /**
     * Net sale: not cancelled and not a post-sale reversal (claim_open still counts as successful).
     *
     * @param  Builder<\App\Models\Order>  $query
     * @return Builder<\App\Models\Order>
     */
    public static function scopeSuccessful(Builder $query): Builder
    {
        return $query
            ->whereNotIn('status', self::cancelledStatuses())
            ->where(function (Builder $inner) {
                $inner->whereNull('post_sale_outcome')
                    ->orWhereNotIn('post_sale_outcome', self::reversedOutcomes());
            });
    }

    /**
     * @param  Builder<\App\Models\Order>  $query
     * @return Builder<\App\Models\Order>
     */
    public static function scopeCancelled(Builder $query): Builder
    {
        return $query->whereIn('status', self::cancelledStatuses());
    }

    /**
     * @param  Builder<\App\Models\Order>  $query
     * @return Builder<\App\Models\Order>
     */
    public static function scopePostSaleReversed(Builder $query): Builder
    {
        return $query->whereIn('post_sale_outcome', self::reversedOutcomes());
    }

    /**
     * @param  Builder<\App\Models\Order>  $query
     * @return Builder<\App\Models\Order>
     */
    public static function scopeClaimOpen(Builder $query): Builder
    {
        return $query->where('post_sale_outcome', ResolveOrderPostSaleOutcome::CLAIM_OPEN);
    }
}
