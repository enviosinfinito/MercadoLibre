<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Variant;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Variant>
 */
class VariantFactory extends Factory
{
    protected $model = Variant::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'product_id' => Product::factory(),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####')),
            'gtin' => null,
            'name' => fake()->optional()->words(2, true),
            'status' => 'active',
        ];
    }
}
