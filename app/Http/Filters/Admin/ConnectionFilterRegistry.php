<?php

declare(strict_types=1);

namespace App\Http\Filters\Admin;

use Illuminate\Database\Eloquent\Builder;

final class ConnectionFilterRegistry
{
    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function apply(Builder $query, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('external_user_id', 'like', "%{$search}%")
                    ->orWhere('site_id', 'like', "%{$search}%")
                    ->orWhereHas('workspace', function (Builder $ws) use ($search): void {
                        $ws->where('name', 'like', "%{$search}%")
                            ->orWhere('slug', 'like', "%{$search}%");
                    });
            });
        }

        $provider = trim((string) ($filters['provider'] ?? ''));
        if ($provider !== '') {
            $query->where('provider', $provider);
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', $status);
        }

        return $query;
    }
}
