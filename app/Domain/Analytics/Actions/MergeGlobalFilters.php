<?php

namespace App\Domain\Analytics\Actions;

use App\Domain\Shared\Support\BusinessDay;

/**
 * Merges dashboard-level global filters into a widget query AST.
 */
final class MergeGlobalFilters
{
    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $globalFilters
     * @param  array<string, mixed>|null  $runtimeFilters
     * @return array<string, mixed>
     */
    public function handle(array $query, ?array $globalFilters, ?array $runtimeFilters = null): array
    {
        $filters = array_values($query['filters'] ?? []);

        foreach ([$globalFilters, $runtimeFilters] as $extra) {
            if (! is_array($extra)) {
                continue;
            }
            // Support shape: { filters: [...], connection_id, date_from, date_to }
            if (isset($extra['filters']) && is_array($extra['filters'])) {
                foreach ($extra['filters'] as $filter) {
                    if (is_array($filter) && isset($filter['field'], $filter['op'])) {
                        $filters[] = $filter;
                    }
                }
            }
            if (! empty($extra['connection_id'])) {
                $filters[] = [
                    'field' => 'connection_id',
                    'op' => is_array($extra['connection_id']) ? 'in' : 'eq',
                    'value' => $extra['connection_id'],
                ];
            }
            if (! empty($extra['date_from']) || ! empty($extra['date_to'])) {
                $dateFrom = is_string($extra['date_from'] ?? null) && $extra['date_from'] !== ''
                    ? (string) $extra['date_from']
                    : '1970-01-01';
                $dateTo = is_string($extra['date_to'] ?? null) && $extra['date_to'] !== ''
                    ? (string) $extra['date_to']
                    : BusinessDay::today()->toDateString();

                [$fromUtc, $toUtcExclusive] = BusinessDay::utcRangeForDateStrings($dateFrom, $dateTo);

                $filters[] = [
                    'field' => $extra['date_field'] ?? 'ordered_at',
                    'op' => 'between',
                    // Inclusive local days → UTC instants; end is last instant of date_to.
                    'value' => [
                        $fromUtc?->toDateTimeString() ?? '1970-01-01 00:00:00',
                        $toUtcExclusive?->copy()->subSecond()->toDateTimeString() ?? now()->toDateTimeString(),
                    ],
                ];
            }
        }

        $query['filters'] = $filters;

        return $query;
    }
}
