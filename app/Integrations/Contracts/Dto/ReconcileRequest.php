<?php

namespace App\Integrations\Contracts\Dto;

readonly class ReconcileRequest
{
    public function __construct(
        public string $resource = '',
        public array $options = [],
    ) {}
}
