<?php

namespace App\Integrations\Contracts\Dto;

readonly class ReconcileResult
{
    public function __construct(
        public array $payload = [],
    ) {}
}
