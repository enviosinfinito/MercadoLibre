<?php

declare(strict_types=1);

namespace App\Http\Filters\Support;

final class FilterContext
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly ?int $workspaceId = null,
        public readonly array $extra = [],
    ) {}
}
