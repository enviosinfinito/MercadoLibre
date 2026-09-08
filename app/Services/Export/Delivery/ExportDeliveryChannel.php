<?php

namespace App\Services\Export\Delivery;

use App\Models\ExportRun;

interface ExportDeliveryChannel
{
    public function type(): string;

    /**
     * @param  array{type: string, value: string}  $channelConfig
     */
    public function send(ExportRun $run, array $channelConfig): void;
}
