<?php

namespace App\Integrations\Contracts\Dto;

readonly class TokenResult
{
    public function __construct(
        public array $payload = [],
    ) {}
}
