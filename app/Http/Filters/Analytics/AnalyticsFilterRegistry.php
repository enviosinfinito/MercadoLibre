<?php

declare(strict_types=1);

namespace App\Http\Filters\Analytics;

final class AnalyticsFilterRegistry
{
    /**
     * Normalize runtime analytics filters (applied via MergeGlobalFilters, not Eloquent).
     *
     * @param  array<string, mixed>  $filters
     * @return array{date_from: string|null, date_to: string|null, connection_id: string|null, date_field: string}
     */
    public function normalize(array $filters): array
    {
        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        $connectionId = $filters['connection_id'] ?? null;
        $dateField = trim((string) ($filters['date_field'] ?? 'ordered_at')) ?: 'ordered_at';

        return [
            'date_from' => $dateFrom !== '' ? $dateFrom : null,
            'date_to' => $dateTo !== '' ? $dateTo : null,
            'connection_id' => $connectionId !== null && $connectionId !== ''
                ? (string) $connectionId
                : null,
            'date_field' => $dateField,
        ];
    }
}
