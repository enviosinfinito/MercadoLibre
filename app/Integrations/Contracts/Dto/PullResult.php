<?php

namespace App\Integrations\Contracts\Dto;

readonly class PullResult
{
    public function __construct(
        public array $items = [],
        public ?array $nextCursor = null,
    ) {}
}
