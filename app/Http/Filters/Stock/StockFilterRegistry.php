<?php

declare(strict_types=1);

namespace App\Http\Filters\Stock;

use Illuminate\Database\Eloquent\Builder;

final class StockFilterRegistry
{
    /**
     * Applies query-level stock filters (not in-memory low_stock / channel_mismatch).
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function apply(Builder $query, array $filters): Builder
    {
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $query->where(function (Builder $builder) use ($q): void {
                $builder->where('sku', 'like', "%{$q}%")
                    ->orWhere('name', 'like', "%{$q}%")
                    ->orWhereHas('product', fn (Builder $p) => $p->where('name', 'like', "%{$q}%"));
            });
        }

        if (! empty($filters['unmatched'])) {
            $query->whereDoesntHave('channelListingVariants');
        }

        if (! empty($filters['warehouse_id'])) {
            $warehouseId = (int) $filters['warehouse_id'];
            $query->whereHas('inventoryItems', fn (Builder $inner) => $inner->where('warehouse_id', $warehouseId));
        }

        return $query;
    }
}
