<?php

declare(strict_types=1);

namespace App\Http\Filters\Claims;

use Illuminate\Database\Eloquent\Builder;

final class ClaimFilterRegistry
{
    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function apply(Builder $query, array $filters): Builder
    {
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', $status);
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $query->where(function (Builder $inner) use ($q): void {
                $inner->where('external_claim_id', 'like', '%'.$q.'%')
                    ->orWhere('reason', 'like', '%'.$q.'%')
                    ->orWhere('reason_id', 'like', '%'.$q.'%')
                    ->orWhere('type', 'like', '%'.$q.'%')
                    ->orWhere('stage', 'like', '%'.$q.'%');
            });
        }

        return $query;
    }
}
