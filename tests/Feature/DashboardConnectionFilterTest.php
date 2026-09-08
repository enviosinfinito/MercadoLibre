<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\Order;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardConnectionFilterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Connection, 3: Connection}
     */
    private function actingMemberWithTwoConnections(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();

        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        $connectionA = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
            'display_name' => 'Cuenta A',
        ]);

        $connectionB = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
            'display_name' => 'Cuenta B',
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$user, $workspace, $connectionA, $connectionB];
    }

    #[Test]
    public function without_filter_sums_orders_from_all_connections(): void
    {
        [, $workspace, $connectionA, $connectionB] = $this->actingMemberWithTwoConnections();

        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connectionA->id,
            'external_order_id' => 'A-1',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '100',
            'ordered_at' => now(),
        ]);

        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connectionB->id,
            'external_order_id' => 'B-1',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '50',
            'ordered_at' => now(),
        ]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('kpis.orders_today', 2)
                ->where('kpis.revenue_today', 150)
                ->where('filters.connection_ids', [])
                ->has('connections', 2));
    }

    #[Test]
    public function connection_ids_filter_scopes_kpis_to_selected_connections(): void
    {
        [, $workspace, $connectionA, $connectionB] = $this->actingMemberWithTwoConnections();

        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connectionA->id,
            'external_order_id' => 'A-1',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '100',
            'ordered_at' => now(),
        ]);

        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connectionB->id,
            'external_order_id' => 'B-1',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '50',
            'ordered_at' => now(),
        ]);

        $this->get(route('dashboard', ['connection_ids' => [$connectionA->id]]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('kpis.orders_today', 1)
                ->where('kpis.revenue_today', 100)
                ->where('sales_quality.successful_orders', 1)
                ->where('sales_quality.successful_revenue', 100)
                ->where('filters.connection_ids', [$connectionA->id])
                ->where('kpis.connections_total', 1));
    }

    #[Test]
    public function foreign_connection_ids_are_ignored(): void
    {
        [, $workspace, $connectionA] = $this->actingMemberWithTwoConnections();

        $otherWorkspace = Workspace::factory()->create();
        $foreign = Connection::factory()->create([
            'workspace_id' => $otherWorkspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);

        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connectionA->id,
            'external_order_id' => 'A-1',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '100',
            'ordered_at' => now(),
        ]);

        $this->get(route('dashboard', ['connection_ids' => [$foreign->id]]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('kpis.orders_today', 1)
                ->where('kpis.revenue_today', 100)
                ->where('filters.connection_ids', []));
    }

    #[Test]
    public function selecting_all_connection_ids_clears_filter_prop(): void
    {
        [, $workspace, $connectionA, $connectionB] = $this->actingMemberWithTwoConnections();

        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connectionA->id,
            'external_order_id' => 'A-1',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '100',
            'ordered_at' => now(),
        ]);

        $this->get(route('dashboard', [
            'connection_ids' => [$connectionA->id, $connectionB->id],
        ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('filters.connection_ids', [])
                ->where('kpis.orders_today', 1));
    }
}
