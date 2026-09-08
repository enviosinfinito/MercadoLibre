<?php

namespace App\Integrations\Contracts;

readonly class CapabilityMatrix
{
    /**
     * @param  array<string, bool>  $capabilities
     */
    public function __construct(
        public array $capabilities = [],
    ) {}

    public function supports(string $capability): bool
    {
        return (bool) ($this->capabilities[$capability] ?? false);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param  array<string, bool>  $capabilities
     */
    public static function make(array $capabilities): self
    {
        return new self($capabilities);
    }
}
