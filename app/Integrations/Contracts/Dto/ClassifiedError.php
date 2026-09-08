<?php

namespace App\Integrations\Contracts\Dto;

readonly class ClassifiedError
{
    public function __construct(
        public string $category = 'unknown',
        public bool $retryable = false,
        public string $message = '',
        public array $context = [],
    ) {}
}
