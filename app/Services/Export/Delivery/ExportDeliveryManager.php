<?php

namespace App\Services\Export\Delivery;

use App\Models\ExportRun;
use RuntimeException;

final class ExportDeliveryManager
{
    /** @var array<string, ExportDeliveryChannel> */
    private array $channels = [];

    /**
     * @param  iterable<ExportDeliveryChannel>  $channels
     */
    public function __construct(iterable $channels = [])
    {
        foreach ($channels as $channel) {
            $this->register($channel);
        }
    }

    public function register(ExportDeliveryChannel $channel): void
    {
        $this->channels[$channel->type()] = $channel;
    }

    /**
     * @return list<string>
     */
    public function enabledTypes(): array
    {
        return array_values(array_intersect(
            array_keys($this->channels),
            config('export.enabled_delivery_channels', ['email']),
        ));
    }

    /**
     * @param  list<array{type: string, value: string}>  $channels
     */
    public function deliver(ExportRun $run, array $channels): void
    {
        foreach ($channels as $channelConfig) {
            $type = (string) ($channelConfig['type'] ?? '');
            if ($type === '') {
                continue;
            }
            if (! in_array($type, config('export.enabled_delivery_channels', ['email']), true)) {
                throw new RuntimeException("Delivery channel [{$type}] is not enabled.");
            }
            $driver = $this->channels[$type] ?? null;
            if (! $driver) {
                throw new RuntimeException("Delivery channel [{$type}] is not registered.");
            }
            $driver->send($run, $channelConfig);
        }
    }
}
