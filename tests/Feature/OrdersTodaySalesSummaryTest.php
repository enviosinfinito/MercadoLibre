<?php

namespace Tests\Feature;

use App\Domain\Shared\Support\BusinessDay;
use App\Models\Connection;
use App\Models\Order;
use App\Models\ProfitSnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrdersTodaySalesSummaryTest extends TestCase
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
    public function orders_index_exposes_today_sales_kpis_for_successful_orders_only(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithConnection();

        $todayLocal = BusinessDay::today();
        [$todayStartUtc] = BusinessDay::utcRangeForDate($todayLocal);
        [$yesterdayStartUtc] = BusinessDay::utcRangeForDate($todayLocal->copy()->subDay());

        $okToday = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'OK-TODAY',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '100',
            'ordered_at' => $todayStartUtc->copy()->addHour(),
        ]);

        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'OK-TODAY-2',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '50',
            'ordered_at' => $todayStartUtc->copy()->addHours(2),
        ]);

        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'OK-YESTERDAY',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '80',
            'ordered_at' => $yesterdayStartUtc->copy()->addHour(),
        ]);

        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'CANCEL-TODAY',
            'status' => 'cancelled',
            'currency_code' => 'MXN',
            'total_amount' => '200',
            'ordered_at' => $todayStartUtc->copy()->addMinutes(30),
        ]);

        ProfitSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'order_id' => $okToday->id,
            'stage' => 'expected',
            'revenue_amount' => 100,
            'fees_amount' => 20,
            'cogs_amount' => 30,
            'profit_amount' => 50,
            'currency_code' => 'MXN',
            'is_incomplete' => false,
        ]);

        $this->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Index')
                ->where('today_sales.orders_today', 2)
                ->where('today_sales.orders_yesterday', 1)
                ->where('today_sales.revenue_today', 150)
                ->where('today_sales.revenue_yesterday', 80)
                ->where('today_sales.expected_profit_today', 50)
                ->where('today_sales.incomplete_orders_today', 0)
                ->where('today_sales.avg_ticket_today', 75)
                ->where('today_sales.currency', 'MXN')
                ->where('today_sales.date', $todayLocal->toDateString()));
    }

    #[Test]
    public function today_sales_respects_connection_filter(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithConnection();

        $other = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);

        $todayLocal = BusinessDay::today();
        [$todayStartUtc] = BusinessDay::utcRangeForDate($todayLocal);

        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'OK-CONN-A',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '100',
            'ordered_at' => $todayStartUtc->copy()->addHour(),
        ]);

        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $other->id,
            'external_order_id' => 'OK-CONN-B',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '250',
            'ordered_at' => $todayStartUtc->copy()->addHours(2),
        ]);

        $this->get(route('orders.index', ['connection_id' => $other->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Index')
                ->where('today_sales.orders_today', 1)
                ->where('today_sales.revenue_today', 250)
                ->where('today_sales.avg_ticket_today', 250));
    }

    #[Test]
    public function json_paginator_does_not_include_today_sales(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithConnection();

        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'OK-JSON',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '100',
            'ordered_at' => now(),
        ]);

        $this->getJson(route('orders.index', ['page' => 1]))
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page', 'last_page', 'total'])
            ->assertJsonMissingPath('today_sales');
    }
}
