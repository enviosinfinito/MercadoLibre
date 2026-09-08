<?php

namespace App\Integrations\Contracts\Dto;

readonly class PushResult
{
    public function __construct(
        public array $payload = [],
    ) {}
}
