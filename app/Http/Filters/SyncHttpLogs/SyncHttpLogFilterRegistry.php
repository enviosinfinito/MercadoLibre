<?php

declare(strict_types=1);

namespace App\Http\Filters\SyncHttpLogs;

use App\Domain\Shared\Support\BusinessDay;
use Illuminate\Database\Eloquent\Builder;

final class SyncHttpLogFilterRegistry
{
    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function apply(Builder $query, array $filters): Builder
    {
        $direction = trim((string) ($filters['http_direction'] ?? $filters['direction'] ?? ''));
        if ($direction !== '' && in_array($direction, ['in', 'out'], true)) {
            $query->where('direction', $direction);
        }

        $statusGroup = trim((string) ($filters['status'] ?? ''));
        if ($statusGroup === 'success') {
            $query->whereBetween('response_status', [200, 399]);
        } elseif ($statusGroup === 'error') {
            $query->where(function (Builder $inner): void {
                $inner->whereNull('response_status')
                    ->orWhere('response_status', '>=', 400);
            });
        }

        if (! empty($filters['connection_id'])) {
            $query->where('connection_id', (int) $filters['connection_id']);
        }

        $topic = trim((string) ($filters['topic'] ?? ''));
        if ($topic !== '') {
            $normalized = strtolower($topic);
            $query->where(function (Builder $inner) use ($normalized): void {
                $inner->where('endpoint_group', 'webhooks.'.$normalized)
                    ->orWhere(function (Builder $legacy) use ($normalized): void {
                        $legacy->where('direction', 'in')
                            ->where('endpoint_group', 'webhooks')
                            ->where('request_body_redacted', 'like', '%"topic":"'.$normalized.'"%');
                    });
            });
        }

        [$fromUtc, $toUtcExclusive] = BusinessDay::utcRangeForDateStrings(
            ! empty($filters['from']) ? (string) $filters['from'] : null,
            ! empty($filters['to']) ? (string) $filters['to'] : null,
        );

        if ($fromUtc !== null) {
            $query->where('created_at', '>=', $fromUtc);
        }

        if ($toUtcExclusive !== null) {
            $query->where('created_at', '<', $toUtcExclusive);
        }

        if (! empty($filters['orphan'])) {
            $query->whereNull('sync_run_id');
        }

        $bodySearch = trim((string) ($filters['body_search'] ?? ''));
        if ($bodySearch !== '') {
            $like = '%'.$bodySearch.'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('request_body_redacted', 'like', $like)
                    ->orWhere('response_body_redacted', 'like', $like);
            });
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $query->where(function (Builder $inner) use ($q): void {
                $inner->where('url', 'like', '%'.$q.'%')
                    ->orWhere('endpoint_group', 'like', '%'.$q.'%')
                    ->orWhere('correlation_id', 'like', '%'.$q.'%')
                    ->orWhere('provider', 'like', '%'.$q.'%')
                    ->orWhere('id', $q);
            });
        }

        return $query;
    }
}
