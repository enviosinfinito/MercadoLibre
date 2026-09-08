<?php

namespace Database\Factories;

use App\Models\Warehouse;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'code' => strtoupper(fake()->unique()->bothify('WH-###')),
            'name' => fake()->words(2, true),
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'DEFAULT',
            'name' => 'Default',
            'is_default' => true,
        ]);
    }
}
