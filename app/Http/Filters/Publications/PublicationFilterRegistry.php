<?php

declare(strict_types=1);

namespace App\Http\Filters\Publications;

use Illuminate\Database\Eloquent\Builder;

final class PublicationFilterRegistry
{
    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function apply(Builder $query, array $filters): Builder
    {
        if (! empty($filters['connection_id'])) {
            $query->where('connection_id', (int) $filters['connection_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $query->where(function (Builder $builder) use ($q): void {
                $builder->where('title', 'like', '%'.$q.'%')
                    ->orWhere('external_item_id', 'like', '%'.$q.'%');
            });
        }

        $matched = $filters['matched'] ?? 'any';
        if ($matched === 'yes') {
            $query->whereHas('variants', fn (Builder $inner) => $inner->whereNotNull('variant_id'));
        } elseif ($matched === 'no') {
            $query->whereDoesntHave('variants', fn (Builder $inner) => $inner->whereNotNull('variant_id'));
        }

        if (! empty($filters['without_cost'])) {
            $query->withoutCost();
        }

        return $query;
    }
}
