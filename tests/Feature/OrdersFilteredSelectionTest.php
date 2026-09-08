<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\Order;
use App\Models\ProfitSnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrdersFilteredSelectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Workspace, 1: Connection}
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

        return [$workspace, $connection];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createOrder(Workspace $workspace, Connection $connection, array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-'.uniqid(),
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '100',
            'ordered_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function all_ids_respects_status_filter(): void
    {
        [$workspace, $connection] = $this->actingMemberWithConnection();

        $paid = $this->createOrder($workspace, $connection, [
            'status' => 'paid',
            'total_amount' => '50.5',
        ]);
        $this->createOrder($workspace, $connection, [
            'status' => 'pending',
            'total_amount' => '999',
        ]);

        $this->getJson(route('orders.all-ids', ['status' => 'paid']))
            ->assertOk()
            ->assertJsonPath('total_count', 1)
            ->assertJsonPath('record_ids', [$paid->id]);
    }

    #[Test]
    public function filtered_sums_returns_total_amount_for_filter(): void
    {
        [$workspace, $connection] = $this->actingMemberWithConnection();

        $this->createOrder($workspace, $connection, [
            'status' => 'paid',
            'total_amount' => '10.25',
        ]);
        $this->createOrder($workspace, $connection, [
            'status' => 'paid',
            'total_amount' => '20.75',
        ]);
        $this->createOrder($workspace, $connection, [
            'status' => 'cancelled',
            'total_amount' => '1000',
        ]);

        $this->getJson(route('orders.filtered-sums', ['status' => 'paid']))
            ->assertOk()
            ->assertJsonPath('total_count', 2)
            ->assertJsonPath('sums_by_key.total_amount', 31);
    }

    #[Test]
    public function filtered_sums_includes_expected_profit_snapshot_totals(): void
    {
        [$workspace, $connection] = $this->actingMemberWithConnection();

        $a = $this->createOrder($workspace, $connection, [
            'status' => 'paid',
            'total_amount' => '100',
        ]);
        $b = $this->createOrder($workspace, $connection, [
            'status' => 'paid',
            'total_amount' => '200',
        ]);

        ProfitSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'order_id' => $a->id,
            'stage' => 'expected',
            'revenue_amount' => '100',
            'fees_amount' => '20',
            'cogs_amount' => '30',
            'profit_amount' => '40',
            'currency_code' => 'MXN',
            'is_incomplete' => false,
            'payload' => [
                'taxes_retention_total' => '10',
                'marketplace_net_amount' => '70',
            ],
        ]);
        ProfitSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'order_id' => $b->id,
            'stage' => 'expected',
            'revenue_amount' => '200',
            'fees_amount' => '40',
            'cogs_amount' => '50',
            'profit_amount' => '90',
            'currency_code' => 'MXN',
            'is_incomplete' => false,
            'payload' => [
                'taxes_retention_total' => '20',
                'marketplace_net_amount' => '140',
            ],
        ]);

        $this->getJson(route('orders.filtered-sums', ['status' => 'paid']))
            ->assertOk()
            ->assertJsonPath('sums_by_key.total_amount', 300)
            ->assertJsonPath('sums_by_key.profit_revenue', 300)
            ->assertJsonPath('sums_by_key.profit_fees', 60)
            ->assertJsonPath('sums_by_key.profit_taxes', 30)
            ->assertJsonPath('sums_by_key.profit_marketplace_net', 210)
            ->assertJsonPath('sums_by_key.profit_cogs', 80)
            ->assertJsonPath('sums_by_key.profit_amount', 130);
    }
}
