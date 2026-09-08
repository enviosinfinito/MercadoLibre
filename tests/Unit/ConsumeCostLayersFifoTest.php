<?php

namespace Tests\Unit;

use App\Domain\Inventory\Actions\ConsumeCostLayersFifo;
use App\Domain\Shared\Support\TenantContext;
use App\Models\CostLayer;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Variant;
use App\Models\Workspace;
use App\Models\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConsumeCostLayersFifoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_consumes_oldest_layers_first(): void
    {
        $workspace = Workspace::factory()->create();
        TenantContext::set($workspace->id);

        $connection = Connection::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '1',
            'status' => 'active',
        ]);

        $product = Product::factory()->create(['workspace_id' => $workspace->id]);
        $variant = Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'sku' => 'FIFO-1',
        ]);

        CostLayer::query()->create([
            'workspace_id' => $workspace->id,
            'variant_id' => $variant->id,
            'qty_original' => '5',
            'qty_remaining' => '5',
            'unit_cost_amount' => '10',
            'unit_cost_currency' => 'MXN',
            'fx_rate' => '1',
            'unit_cost_reporting_amount' => '10',
            'reporting_currency' => 'MXN',
            'received_at' => now()->subDays(2),
        ]);

        CostLayer::query()->create([
            'workspace_id' => $workspace->id,
            'variant_id' => $variant->id,
            'qty_original' => '5',
            'qty_remaining' => '5',
            'unit_cost_amount' => '20',
            'unit_cost_currency' => 'MXN',
            'fx_rate' => '1',
            'unit_cost_reporting_amount' => '20',
            'reporting_currency' => 'MXN',
            'received_at' => now()->subDay(),
        ]);

        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-1',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '100',
        ]);

        $line = OrderLine::query()->create([
            'workspace_id' => $workspace->id,
            'order_id' => $order->id,
            'connection_id' => $connection->id,
            'variant_id' => $variant->id,
            'sku' => 'FIFO-1',
            'quantity' => '7',
            'unit_price_amount' => '30',
            'currency_code' => 'MXN',
            'line_total_amount' => '210',
            'match_status' => 'matched',
        ]);

        $result = app(ConsumeCostLayersFifo::class)->execute($line);

        // 5*10 + 2*20 = 90
        $this->assertSame('90.000000', $result['total_cogs']);
        $this->assertCount(2, $result['consumptions']);

        TenantContext::clear();
    }
}
