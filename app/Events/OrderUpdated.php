<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast immediately (not via the default queue) so sale sounds / live UI
 * are not delayed or dropped when Horizon is not consuming `default`.
 */
class OrderUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly bool $isNew = false,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('workspace.'.$this->order->workspace_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->order->id,
            'external_order_id' => $this->order->external_order_id,
            'status' => $this->order->status,
            'total_amount' => (string) $this->order->total_amount,
            'currency_code' => $this->order->currency_code,
            'is_new' => $this->isNew,
        ];
    }
}
