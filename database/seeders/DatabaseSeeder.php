<?php

namespace Database\Seeders;

use App\Models\CostLayer;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Models\Warehouse;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => 'admin@saas.test'],
            [
                'name' => 'Demo Admin',
                'password' => Hash::make('password'),
                'is_platform_admin' => true,
                'email_verified_at' => now(),
            ],
        );

        $workspace = Workspace::query()->updateOrCreate(
            ['slug' => 'demo'],
            [
                'name' => 'Demo',
                'reporting_currency' => 'MXN',
                'default_costing_method' => 'fifo',
            ],
        );

        WorkspaceMembership::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
            ],
            [
                'role_name' => 'owner',
            ],
        );

        $warehouse = Warehouse::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'code' => 'DEFAULT',
            ],
            [
                'name' => 'Default warehouse',
                'is_default' => true,
            ],
        );

        $product = Product::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'name' => 'Demo Product',
            ],
            [
                'description' => 'Seeded demo product with USD COGS layer',
                'status' => 'active',
            ],
        );

        $variant = Variant::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'sku' => 'DEMO-001',
            ],
            [
                'product_id' => $product->id,
                'gtin' => null,
                'name' => 'Default',
                'status' => 'active',
            ],
        );

        CostLayer::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'variant_id' => $variant->id,
                'warehouse_id' => $warehouse->id,
                'source_type' => 'receipt',
                'notes' => 'Demo layer: 10 USD @ FX 20 => 200 MXN/u',
            ],
            [
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
                'received_at' => now(),
            ],
        );

        $this->call([
            AnalyticsPermissionSeeder::class,
            AnalyticsTemplateSeeder::class,
            ReturnReasonSeeder::class,
        ]);
    }
}
