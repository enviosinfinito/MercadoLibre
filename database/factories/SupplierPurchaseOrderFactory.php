<?php

namespace Database\Factories;

use App\Models\SupplierPurchaseOrder;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierPurchaseOrder>
 */
class SupplierPurchaseOrderFactory extends Factory
{
    protected $model = SupplierPurchaseOrder::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'supplier_name' => fake()->company(),
            'status' => SupplierPurchaseOrder::STATUS_ORDERED,
            'ordered_at' => now(),
            'notes' => null,
        ];
    }
}
