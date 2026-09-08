<?php

declare(strict_types=1);

namespace App\Http\Filters\Products;

use Illuminate\Database\Eloquent\Builder;

final class ProductFilterRegistry
{
    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function apply(Builder $query, array $filters): Builder
    {
        if (! empty($filters['archived'])) {
            $query->onlyTrashed();
        }

        if (! empty($filters['without_cost'])) {
            $query->withoutCost();
        }

        return $query;
    }
}
