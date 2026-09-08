<?php

namespace Database\Factories;

use App\Models\CostLayer;
use App\Models\Variant;
use App\Models\Warehouse;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CostLayer>
 */
class CostLayerFactory extends Factory
{
    protected $model = CostLayer::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'variant_id' => Variant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'qty_original' => '10.000000',
            'qty_remaining' => '10.000000',
            'unit_cost_amount' => '10.000000',
            'unit_cost_currency' => 'USD',
            'fx_rate' => '20.000000',
            'fx_from' => 'USD',
            'fx_to' => 'MXN',
            'fx_source' => 'manual',
            'fx_dated_at' => now(),
            'unit_cost_reporting_amount' => '200.000000',
            'reporting_currency' => 'MXN',
            'source_type' => 'receipt',
            'notes' => null,
            'received_at' => now(),
        ];
    }
}
