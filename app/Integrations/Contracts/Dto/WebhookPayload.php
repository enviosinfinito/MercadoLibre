<?php

namespace App\Integrations\Contracts\Dto;

readonly class WebhookPayload
{
    public function __construct(
        public array $headers = [],
        public array|string $body = [],
    ) {}
}
