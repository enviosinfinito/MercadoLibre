<?php

namespace App\Integrations\Contracts\Dto;

readonly class AuthResult
{
    public function __construct(
        public array $payload = [],
    ) {}
}
