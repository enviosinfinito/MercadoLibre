<?php

declare(strict_types=1);

namespace App\Http\Filters\Contracts;

use Illuminate\Database\Eloquent\Builder;

interface ListFilter
{
    public function key(): string;

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $filters
     */
    public function apply(Builder $query, array $filters): void;
}
