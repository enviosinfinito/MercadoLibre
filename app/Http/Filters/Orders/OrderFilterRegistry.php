<?php

declare(strict_types=1);

namespace App\Http\Filters\Orders;

use App\Domain\Cash\Support\OverdueReleaseQuery;
use App\Domain\Shared\Support\BusinessDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class OrderFilterRegistry
{
    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Model>
     */
    public function apply(Builder $query, array $filters): Builder
    {
        $tab = $filters['tab'] ?? null;
        $status = $filters['status'] ?? null;
        $releaseIssue = isset($filters['release_issue']) ? trim((string) $filters['release_issue']) : '';

        if ($releaseIssue !== '' && in_array($releaseIssue, OverdueReleaseQuery::kinds(), true)) {
            app(OverdueReleaseQuery::class)->constrain($query, $releaseIssue);
            $status = null;
        } elseif ($tab && ! $status) {
            $status = match ($tab) {
                'paid' => 'paid',
                'pending' => 'pending',
                'shipped', 'in_transit' => 'shipped',
                'delivered' => 'delivered',
                'cancelled' => 'cancelled',
                default => null,
            };
        }

        if (! empty($filters['connection_id'])) {
            $query->where('connection_id', (int) $filters['connection_id']);
        }

        if ($status) {
            $query->where('status', $status);
        }

        [$fromUtc, $toUtcExclusive] = BusinessDay::utcRangeForDateStrings(
            ! empty($filters['from']) ? (string) $filters['from'] : null,
            ! empty($filters['to']) ? (string) $filters['to'] : null,
        );

        if ($fromUtc !== null) {
            $query->where('ordered_at', '>=', $fromUtc);
        }

        if ($toUtcExclusive !== null) {
            $query->where('ordered_at', '<', $toUtcExclusive);
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $query->where(function (Builder $builder) use ($q): void {
                $builder->where('external_order_id', 'like', '%'.$q.'%')
                    ->orWhere('buyer_external_id', 'like', '%'.$q.'%')
                    ->orWhere('id', $q);
            });
        }

        return $query;
    }
}
