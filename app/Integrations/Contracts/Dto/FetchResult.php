<?php

namespace App\Integrations\Contracts\Dto;

readonly class FetchResult
{
    public function __construct(
        public array $payload = [],
    ) {}
}
