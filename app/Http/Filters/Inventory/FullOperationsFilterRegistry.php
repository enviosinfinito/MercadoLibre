<?php

declare(strict_types=1);

namespace App\Http\Filters\Inventory;

use App\Domain\Inventory\Support\FullStockOperationType;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class FullOperationsFilterRegistry
{
    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Model>
     */
    public function apply(Builder $query, array $filters, ?int $workspaceId = null): Builder
    {
        $exactType = strtoupper(trim((string) ($filters['operation_type'] ?? '')));
        if ($exactType !== '') {
            $query->where('operation_type', $exactType);
        } else {
            $this->applyTab($query, (string) ($filters['tab'] ?? ''));
        }

        if (! empty($filters['variant_id'])) {
            $query->where('variant_id', (int) $filters['variant_id']);
        }

        if (! empty($filters['connection_id'])) {
            $query->where('connection_id', (int) $filters['connection_id']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('occurred_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('occurred_at', '<=', $filters['to']);
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $query->where(function (Builder $builder) use ($q, $workspaceId): void {
                $builder->where('inventory_id', 'like', "%{$q}%")
                    ->orWhere('seller_product_id', 'like', "%{$q}%")
                    ->orWhere('external_operation_id', 'like', "%{$q}%");

                if ($workspaceId !== null) {
                    $variantIds = Variant::query()
                        ->where('workspace_id', $workspaceId)
                        ->where(function (Builder $inner) use ($q): void {
                            $inner->where('sku', 'like', "%{$q}%")
                                ->orWhere('name', 'like', "%{$q}%");
                        })
                        ->limit(200)
                        ->pluck('id');

                    if ($variantIds->isNotEmpty()) {
                        $builder->orWhereIn('variant_id', $variantIds);
                    }
                }
            });
        }

        return $query;
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyTab(Builder $query, string $tab): void
    {
        $tab = strtolower(trim($tab));
        if ($tab === '' || $tab === FullStockOperationType::FAMILY_ALL) {
            return;
        }

        if ($tab === FullStockOperationType::FAMILY_OTHER) {
            $known = FullStockOperationType::allKnownTypes();
            if ($known !== []) {
                $query->where(function (Builder $builder) use ($known): void {
                    $builder->whereNotIn('operation_type', $known)
                        ->orWhereNull('operation_type');
                });
            }

            return;
        }

        $types = FullStockOperationType::typesForFamily($tab);
        if ($types === null || $types === []) {
            return;
        }

        $query->whereIn('operation_type', $types);
    }
}
