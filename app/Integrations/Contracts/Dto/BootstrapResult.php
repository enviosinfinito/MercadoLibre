<?php

namespace App\Integrations\Contracts\Dto;

readonly class BootstrapResult
{
    public function __construct(
        public array $payload = [],
    ) {}
}
