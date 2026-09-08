<?php

namespace Tests\Feature;

use App\Domain\PostSale\Actions\ResolveOrderPostSaleOutcome;
use App\Models\Connection;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardSalesQualityTest extends TestCase
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
    public function dashboard_kpis_count_only_successful_sales_and_expose_sales_quality(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithConnection();

        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'OK-1',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '100',
            'ordered_at' => now()->subMinutes(4),
        ]);

        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'OK-CLAIM',
            'status' => 'paid',
            'post_sale_outcome' => ResolveOrderPostSaleOutcome::CLAIM_OPEN,
            'currency_code' => 'MXN',
            'total_amount' => '50',
            'ordered_at' => now()->subMinutes(3),
        ]);

        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'CANCEL-1',
            'status' => 'cancelled',
            'currency_code' => 'MXN',
            'total_amount' => '200',
            'ordered_at' => now()->subMinutes(2),
        ]);

        $returned = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'RET-1',
            'status' => 'delivered',
            'post_sale_outcome' => ResolveOrderPostSaleOutcome::RETURNED,
            'currency_code' => 'MXN',
            'total_amount' => '80',
            'ordered_at' => now()->subMinute(),
        ]);

        OrderLine::query()->create([
            'order_id' => $returned->id,
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'sku' => 'SKU-RET',
            'title' => 'Producto devuelto',
            'quantity' => '1',
            'unit_price_amount' => '80',
            'currency_code' => 'MXN',
            'line_total_amount' => '80',
        ]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('kpis.orders_today', 2)
                ->where('kpis.revenue_today', 150)
                ->where('kpis.orders_30d', 2)
                ->where('kpis.revenue_30d', 150)
                ->where('sales_quality.total_orders', 4)
                ->where('sales_quality.successful_orders', 2)
                ->where('sales_quality.successful_revenue', 150)
                ->where('sales_quality.cancelled_orders', 1)
                ->where('sales_quality.cancelled_revenue', 200)
                ->where('sales_quality.returned_orders', 1)
                ->where('sales_quality.post_sale_reversed_orders', 1)
                ->where('sales_quality.claim_open_orders', 1)
                ->where('sales_quality.cancellation_rate', 25)
                ->where('sales_quality.return_rate', 25)
                ->has('top_returned_products', 1)
                ->where('top_returned_products.0.sku', 'SKU-RET')
                ->where('top_returned_products.0.orders_count', 1)
                ->where('recent_orders.0.external_order_id', 'RET-1')
                ->where('recent_orders.0.post_sale_outcome', ResolveOrderPostSaleOutcome::RETURNED)
                ->where('filters.connection_ids', []));
    }
}
