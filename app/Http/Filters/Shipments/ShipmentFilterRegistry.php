<?php

declare(strict_types=1);

namespace App\Http\Filters\Shipments;

use Illuminate\Database\Eloquent\Builder;

final class ShipmentFilterRegistry
{
    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function apply(Builder $query, array $filters): Builder
    {
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q === '') {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($q): void {
            $builder->where('external_shipment_id', 'like', '%'.$q.'%')
                ->orWhere('tracking_number', 'like', '%'.$q.'%')
                ->orWhere('carrier', 'like', '%'.$q.'%')
                ->orWhere('status', 'like', '%'.$q.'%')
                ->orWhere('id', $q)
                ->orWhere('order_id', $q);
        });
    }
}
