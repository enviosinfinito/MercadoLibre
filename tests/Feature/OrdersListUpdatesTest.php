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

class OrdersListUpdatesTest extends TestCase
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
    public function list_updates_requires_since_id(): void
    {
        $this->actingMemberWithConnection();

        $this->getJson(route('orders.list-updates'))
            ->assertStatus(422)
            ->assertJson(['message' => 'since_id requerido']);
    }

    #[Test]
    public function list_updates_returns_orders_newer_than_since_id_matching_filters(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithConnection();

        $old = $this->createOrder($workspace, $connection, [
            'external_order_id' => 'ORD-OLD',
            'status' => 'paid',
        ]);
        $newerPaid = $this->createOrder($workspace, $connection, [
            'external_order_id' => 'ORD-NEW-PAID',
            'status' => 'paid',
        ]);
        $this->createOrder($workspace, $connection, [
            'external_order_id' => 'ORD-NEW-PEND',
            'status' => 'pending',
        ]);

        $this->assertGreaterThan($old->id, $newerPaid->id);

        $response = $this->getJson(route('orders.list-updates', [
            'since_id' => $old->id,
            'status' => 'paid',
        ]));

        $response->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $newerPaid->id)
            ->assertJsonPath('data.0.external_order_id', 'ORD-NEW-PAID')
            ->assertJsonStructure([
                'data' => [
                    [
                        'id',
                        'external_order_id',
                        'status',
                        'platform',
                        'buyer_summary',
                        'buyer_display',
                    ],
                ],
                'count',
            ]);
    }

    #[Test]
    public function list_updates_returns_empty_when_no_newer_orders(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithConnection();

        $order = $this->createOrder($workspace, $connection);

        $this->getJson(route('orders.list-updates', [
            'since_id' => $order->id,
        ]))
            ->assertOk()
            ->assertJson([
                'data' => [],
                'count' => 0,
            ]);
    }

    #[Test]
    public function list_updates_orders_by_ordered_at_desc_not_by_id(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithConnection();

        $baseline = $this->createOrder($workspace, $connection, [
            'external_order_id' => 'ORD-BASE',
            'ordered_at' => now()->subHours(3),
        ]);

        // Higher local ids, but older real order timestamps (late sync).
        $olderLateSync = $this->createOrder($workspace, $connection, [
            'external_order_id' => 'ORD-OLD-LATE',
            'ordered_at' => now()->subHours(2),
        ]);
        $newestByOrderDate = $this->createOrder($workspace, $connection, [
            'external_order_id' => 'ORD-NEWEST-DATE',
            'ordered_at' => now()->subMinutes(10),
        ]);
        $midByOrderDate = $this->createOrder($workspace, $connection, [
            'external_order_id' => 'ORD-MID-DATE',
            'ordered_at' => now()->subHour(),
        ]);

        $this->assertGreaterThan($baseline->id, $olderLateSync->id);
        $this->assertGreaterThan($olderLateSync->id, $newestByOrderDate->id);
        $this->assertGreaterThan($newestByOrderDate->id, $midByOrderDate->id);

        $response = $this->getJson(route('orders.list-updates', [
            'since_id' => $baseline->id,
        ]));

        $response->assertOk()
            ->assertJsonPath('count', 3)
            ->assertJsonPath('data.0.id', $newestByOrderDate->id)
            ->assertJsonPath('data.1.id', $midByOrderDate->id)
            ->assertJsonPath('data.2.id', $olderLateSync->id);
    }
}
