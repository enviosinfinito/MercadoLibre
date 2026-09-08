<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\Order;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BuyerPurchaseHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Connection}
     */
    private function actingMember(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['outbound_dry_run' => true]);
        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        $connection = Connection::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '112184176',
            'status' => 'active',
            'token_generation' => 1,
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$user, $workspace, $connection];
    }

    private function makeOrder(
        Workspace $workspace,
        Connection $connection,
        string $externalId,
        string $buyerId,
        string $status,
        string $total,
        ?\DateTimeInterface $orderedAt = null,
    ): Order {
        return Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => $externalId,
            'buyer_external_id' => $buyerId,
            'status' => $status,
            'currency_code' => 'MXN',
            'total_amount' => $total,
            'ordered_at' => $orderedAt ?? now()->subDays(1),
            'paid_at' => $status === 'paid' || $status === 'delivered' ? now()->subDays(1) : null,
            'meta' => [
                'buyer' => [
                    'id' => $buyerId,
                    'nickname' => 'LOJE3629796',
                    'first_name' => 'JESUS ALEJANDRO',
                    'last_name' => 'LOPEZ RAMIREZ',
                ],
            ],
        ]);
    }

    #[Test]
    public function buyer_endpoint_aggregates_orders_like_shopify_customer(): void
    {
        [, $workspace, $connection] = $this->actingMember();

        $current = $this->makeOrder(
            $workspace,
            $connection,
            '200001',
            '529340135',
            'paid',
            '100.000000',
            now()->subDay(),
        );
        $this->makeOrder(
            $workspace,
            $connection,
            '200002',
            '529340135',
            'delivered',
            '250.500000',
            now()->subDays(10),
        );
        $this->makeOrder(
            $workspace,
            $connection,
            '200003',
            '529340135',
            'cancelled',
            '999.000000',
            now()->subDays(3),
        );
        $this->makeOrder(
            $workspace,
            $connection,
            '200099',
            '999999',
            'paid',
            '50.000000',
            now()->subDays(2),
        );

        $response = $this->getJson(route('orders.buyer', $current));

        $response->assertOk();
        $response->assertJsonPath('insights.orders_count', 2);
        $response->assertJsonPath('insights.repeat_customer', true);
        $response->assertJsonPath('insights.lifetime_spent', '350.500000');
        $response->assertJsonPath('insights.currency_code', 'MXN');
        $response->assertJsonPath('buyer.nickname', 'LOJE3629796');

        $ids = collect($response->json('orders'))->pluck('external_order_id')->all();
        $this->assertContains('200001', $ids);
        $this->assertContains('200002', $ids);
        $this->assertContains('200003', $ids);
        $this->assertNotContains('200099', $ids);

        $currentRow = collect($response->json('orders'))->firstWhere('id', $current->id);
        $this->assertTrue($currentRow['is_current']);
    }

    #[Test]
    public function buyer_endpoint_single_order_is_not_repeat(): void
    {
        [, $workspace, $connection] = $this->actingMember();

        $order = $this->makeOrder(
            $workspace,
            $connection,
            '200010',
            '111',
            'paid',
            '80.000000',
        );

        $response = $this->getJson(route('orders.buyer', $order));

        $response->assertOk();
        $response->assertJsonPath('insights.orders_count', 1);
        $response->assertJsonPath('insights.repeat_customer', false);
        $response->assertJsonPath('insights.lifetime_spent', '80.000000');
        $response->assertJsonCount(1, 'orders');
        $response->assertJsonPath('orders.0.is_current', true);
    }

    #[Test]
    public function buyer_endpoint_returns_404_for_other_workspace(): void
    {
        [, $workspace, $connection] = $this->actingMember();
        $order = $this->makeOrder($workspace, $connection, '200020', '222', 'paid', '10.000000');

        $otherUser = User::factory()->create();
        $otherWorkspace = Workspace::factory()->create();
        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $otherWorkspace->id,
            'user_id' => $otherUser->id,
        ]);

        $this->actingAs($otherUser)->withSession(['workspace_id' => $otherWorkspace->id]);

        $this->getJson(route('orders.buyer', $order))->assertNotFound();
    }

    #[Test]
    public function buyer_endpoint_returns_422_without_buyer_id(): void
    {
        [, $workspace, $connection] = $this->actingMember();

        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => '200030',
            'buyer_external_id' => null,
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '10',
            'ordered_at' => now(),
        ]);

        $this->getJson(route('orders.buyer', $order))
            ->assertStatus(422)
            ->assertJsonPath('message', 'La orden no tiene comprador asociado.');
    }
}
