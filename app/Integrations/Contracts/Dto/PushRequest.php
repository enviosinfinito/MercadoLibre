<?php

namespace App\Integrations\Contracts\Dto;

readonly class PushRequest
{
    public function __construct(
        public string $resource = '',
        public array $payload = [],
    ) {}
}
