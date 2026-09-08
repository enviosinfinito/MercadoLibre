<?php

namespace App\Integrations\Contracts\Dto;

readonly class PullRequest
{
    public function __construct(
        public string $resource = '',
        public array $cursor = [],
        public array $options = [],
    ) {}
}
