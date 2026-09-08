<?php

declare(strict_types=1);

namespace App\Http\Filters\Inventory;

use App\Models\Variant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class LedgerFilterRegistry
{
    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Model>
     */
    public function apply(Builder $query, array $filters, ?int $workspaceId = null): Builder
    {
        if (! empty($filters['variant_id'])) {
            $query->where('variant_id', (int) $filters['variant_id']);
        }

        if (! empty($filters['movement_type'])) {
            $query->where('movement_type', $filters['movement_type']);
        }

        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', (int) $filters['warehouse_id']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('occurred_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('occurred_at', '<=', $filters['to']);
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '' && $workspaceId !== null) {
            $variantIds = Variant::query()
                ->where('workspace_id', $workspaceId)
                ->where(function (Builder $builder) use ($q): void {
                    $builder->where('sku', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%");
                })
                ->limit(200)
                ->pluck('id');
            $query->whereIn('variant_id', $variantIds);
        }

        return $query;
    }
}
