<?php

namespace Database\Factories;

use App\Models\SupplierPurchaseOrder;
use App\Models\SupplierPurchaseOrderLine;
use App\Models\Variant;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierPurchaseOrderLine>
 */
class SupplierPurchaseOrderLineFactory extends Factory
{
    protected $model = SupplierPurchaseOrderLine::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'supplier_purchase_order_id' => SupplierPurchaseOrder::factory(),
            'variant_id' => Variant::factory(),
            'qty_ordered' => '1000.000000',
            'qty_received' => '0.000000',
            'unit_cost_amount' => '10.000000',
            'currency' => 'MXN',
        ];
    }
}
