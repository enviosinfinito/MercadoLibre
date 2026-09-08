<?php

namespace App\Integrations\Contracts;

use InvalidArgumentException;

final class ConnectorRegistry
{
    /** @var array<string, ConnectorInterface> */
    private array $connectors = [];

    public function register(string $channel, ConnectorInterface $connector): void
    {
        $this->connectors[$channel] = $connector;
    }

    public function get(string $channel): ConnectorInterface
    {
        if (! isset($this->connectors[$channel])) {
            throw new InvalidArgumentException("No connector registered for channel [{$channel}].");
        }

        return $this->connectors[$channel];
    }

    public function has(string $channel): bool
    {
        return isset($this->connectors[$channel]);
    }

    /**
     * @return array<string, ConnectorInterface>
     */
    public function all(): array
    {
        return $this->connectors;
    }
}
