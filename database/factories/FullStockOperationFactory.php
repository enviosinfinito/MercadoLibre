<?php

namespace Database\Factories;

use App\Models\Connection;
use App\Models\FullStockOperation;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FullStockOperation>
 */
class FullStockOperationFactory extends Factory
{
    protected $model = FullStockOperation::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'connection_id' => Connection::factory(),
            'external_operation_id' => (string) fake()->unique()->numerify('########'),
            'seller_id' => (string) fake()->numerify('########'),
            'inventory_id' => strtoupper(fake()->bothify('????#####')),
            'seller_product_id' => null,
            'operation_type' => fake()->randomElement([
                'INBOUND_RECEPTION',
                'SALE_CONFIRMATION',
                'SALE_CANCELATION',
                'SALE_RETURN',
            ]),
            'occurred_at' => now()->subHours(fake()->numberBetween(1, 72)),
            'available_quantity_delta' => (string) fake()->numberBetween(-10, 50),
            'not_available_quantity_delta' => '0',
            'result_total' => '100',
            'result_available' => '100',
            'result_not_available' => '0',
            'not_available_detail' => [],
            'external_references' => [],
            'raw' => [],
        ];
    }
}
