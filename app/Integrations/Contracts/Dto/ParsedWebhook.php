<?php

namespace App\Integrations\Contracts\Dto;

readonly class ParsedWebhook
{
    public function __construct(
        public string $type = '',
        public array $events = [],
    ) {}
}
