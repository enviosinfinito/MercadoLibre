<?php

namespace App\Integrations\Contracts\Dto;

readonly class FetchRequest
{
    public function __construct(
        public string $resource = '',
        public string $externalId = '',
        public array $options = [],
    ) {}
}
