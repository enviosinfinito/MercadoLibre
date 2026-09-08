<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Services\Listings\FilteredSelectionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

trait RespondsWithFilteredSelection
{
    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    protected function selectionAllIds(Builder $query, string $idColumn = 'id'): JsonResponse
    {
        return app(FilteredSelectionService::class)->allIds($query, $idColumn);
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, string>  $sumExpressions
     */
    protected function selectionFilteredSums(Builder $query, array $sumExpressions): JsonResponse
    {
        return app(FilteredSelectionService::class)->filteredSums($query, $sumExpressions);
    }
}
