<?php

namespace Database\Factories;

use App\Models\Connection;
use App\Models\MarketplaceInbound;
use App\Models\Variant;
use App\Models\Warehouse;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketplaceInbound>
 */
class MarketplaceInboundFactory extends Factory
{
    protected $model = MarketplaceInbound::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'connection_id' => Connection::factory(),
            'variant_id' => Variant::factory(),
            'from_warehouse_id' => Warehouse::factory(),
            'qty_sent' => '300.000000',
            'qty_confirmed' => null,
            'external_inbound_id' => null,
            'full_stock_operation_id' => null,
            'status' => MarketplaceInbound::STATUS_PENDING,
            'sent_at' => now(),
            'matched_at' => null,
            'notes' => null,
        ];
    }
}
