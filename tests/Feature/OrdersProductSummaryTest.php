<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Models\Variant;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrdersProductSummaryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Connection}
     */
    private function actingMemberWithConnection(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();

        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$user, $workspace, $connection];
    }

    #[Test]
    public function orders_index_includes_product_summary_from_primary_line(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithConnection();

        $variant = Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'sku' => 'SKU-PRIMARY',
        ]);

        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-PRODUCT-1',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '200',
            'ordered_at' => now(),
        ]);

        OrderLine::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'variant_id' => $variant->id,
            'external_item_id' => 'MLM123456',
            'sku' => 'SKU-PRIMARY',
            'title' => 'Auriculares Bluetooth',
            'quantity' => '1',
            'unit_price_amount' => '100',
            'currency_code' => 'MXN',
            'line_total_amount' => '100',
            'match_status' => 'matched',
        ]);

        OrderLine::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'external_item_id' => 'MLM999',
            'sku' => 'SKU-SECOND',
            'title' => 'Cable USB',
            'quantity' => '1',
            'unit_price_amount' => '100',
            'currency_code' => 'MXN',
            'line_total_amount' => '100',
            'match_status' => 'unmatched',
        ]);

        $this->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Index')
                ->has('orders.data', 1)
                ->where('orders.data.0.product_summary.title', 'Auriculares Bluetooth')
                ->where('orders.data.0.product_summary.sku', 'SKU-PRIMARY')
                ->where('orders.data.0.product_summary.ml_item_id', 'MLM123456')
                ->where('orders.data.0.product_summary.product_id', $variant->product_id)
                ->where('orders.data.0.product_summary.more_count', 1)
                ->missing('orders.data.0.lines'));
    }

    #[Test]
    public function orders_show_exposes_product_id_on_matched_lines(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithConnection();

        $variant = Variant::factory()->create([
            'workspace_id' => $workspace->id,
        ]);

        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-PRODUCT-SHOW',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '100',
            'ordered_at' => now(),
        ]);

        $line = OrderLine::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'variant_id' => $variant->id,
            'external_item_id' => 'MLM555',
            'sku' => $variant->sku,
            'title' => 'Producto matched',
            'quantity' => '1',
            'unit_price_amount' => '100',
            'currency_code' => 'MXN',
            'line_total_amount' => '100',
            'match_status' => 'matched',
        ]);

        $this->getJson(route('orders.show', $order))
            ->assertOk()
            ->assertJsonPath('order.lines.0.id', $line->id)
            ->assertJsonPath('order.lines.0.product_id', $variant->product_id)
            ->assertJsonPath('order.lines.0.external_item_id', 'MLM555')
            ->assertJsonMissingPath('order.lines.0.variant');
    }
}
