<?php

declare(strict_types=1);

namespace App\Services\Listings;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

final class FilteredSelectionService
{
    public const MAX_IDS = 50_000;

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $idColumn  Qualified or bare id column (default: id)
     */
    public function allIds(Builder $query, string $idColumn = 'id'): JsonResponse
    {
        $count = (clone $query)->toBase()->getCountForPagination();

        if ($count > self::MAX_IDS) {
            return response()->json([
                'message' => 'Hay más de '.number_format(self::MAX_IDS).' registros. Aplica más filtros para seleccionar todos.',
                'total_count' => $count,
                'max' => self::MAX_IDS,
            ], 422);
        }

        $ids = (clone $query)
            ->reorder()
            ->orderBy($idColumn)
            ->pluck($idColumn)
            ->map(fn ($id) => is_numeric($id) ? (int) $id : $id)
            ->values()
            ->all();

        return response()->json([
            'record_ids' => $ids,
            'total_count' => $count,
        ]);
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, string>  $sumExpressions  key => SQL expression (e.g. 'total_amount' => 'COALESCE(SUM(total_amount), 0)')
     */
    public function filteredSums(Builder $query, array $sumExpressions): JsonResponse
    {
        if ($sumExpressions === []) {
            $count = (clone $query)->toBase()->getCountForPagination();

            return response()->json([
                'sums_by_key' => (object) [],
                'total_count' => $count,
            ]);
        }

        $count = (clone $query)->toBase()->getCountForPagination();

        if ($count > self::MAX_IDS) {
            return response()->json([
                'message' => 'Hay más de '.number_format(self::MAX_IDS).' registros. Aplica más filtros para calcular totales.',
                'total_count' => $count,
                'max' => self::MAX_IDS,
            ], 422);
        }

        $selects = ['COUNT(*) as __selection_total_count'];
        foreach ($sumExpressions as $key => $expression) {
            if (! preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                throw new InvalidArgumentException("Invalid sum key: {$key}");
            }
            $selects[] = "{$expression} as {$key}";
        }

        $row = (clone $query)
            ->reorder()
            ->toBase()
            ->selectRaw(implode(', ', $selects))
            ->first();

        $sumsByKey = [];
        foreach (array_keys($sumExpressions) as $key) {
            $sumsByKey[$key] = $row ? (float) ($row->{$key} ?? 0) : 0.0;
        }

        return response()->json([
            'sums_by_key' => $sumsByKey,
            'total_count' => $row ? (int) ($row->__selection_total_count ?? $count) : $count,
        ]);
    }

    /**
     * Convenience: SUM(COALESCE(column, 0)) for simple numeric columns on the base table.
     *
     * @param  list<string>  $columns
     * @return array<string, string>
     */
    public static function coalesceSumExpressions(array $columns, ?string $table = null): array
    {
        $out = [];
        foreach ($columns as $column) {
            if (! preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
                throw new InvalidArgumentException("Invalid column: {$column}");
            }
            $qualified = $table ? "{$table}.{$column}" : $column;
            $out[$column] = 'COALESCE(SUM('.$qualified.'), 0)';
        }

        return $out;
    }
}
