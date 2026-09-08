<?php

namespace Tests\Feature;

use App\Domain\Ads\Actions\AttributeAdvertisingToOrders;
use App\Models\Connection;
use App\Models\FinancialEvent;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrdersAdsAttributionTest extends TestCase
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
     * @return array{0: Order, 1: OrderLine}
     */
    private function seedOrder(Workspace $workspace, Connection $connection): array
    {
        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-ADS-1',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '500',
            'ordered_at' => now()->subDay(),
        ]);

        $line = OrderLine::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'external_item_id' => 'MLM111',
            'sku' => 'SKU-ADS',
            'title' => 'Producto Ads',
            'quantity' => '1',
            'unit_price_amount' => '500',
            'currency_code' => 'MXN',
            'line_total_amount' => '500',
            'match_status' => 'matched',
        ]);

        return [$order, $line];
    }

    #[Test]
    public function orders_index_marks_ads_attribution_when_expected_advertising_exists(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithConnection();
        [$withAds, $line] = $this->seedOrder($workspace, $connection);

        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-ADS-NONE',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '100',
            'ordered_at' => now()->subDays(2),
        ]);

        FinancialEvent::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $withAds->id,
            'order_line_id' => $line->id,
            'event_type' => AttributeAdvertisingToOrders::EVENT_ALLOCATED,
            'stage' => 'expected',
            'amount' => '-42.500000',
            'currency_code' => 'MXN',
            'reporting_amount' => '-42.500000',
            'reporting_currency' => 'MXN',
            'occurred_at' => $withAds->ordered_at,
            'provenance' => [
                'source' => 'product_ads_item_daily',
                'model' => 'blended_acos_ml_rate',
                'ads_cost' => 80,
                'ml_revenue' => 400,
                'item_gmv' => 500,
                'denom' => 500,
                'coverage_ratio' => 0.8,
                'rate' => 0.085,
                'ml_item_id' => 'MLM111',
                'date' => now()->subDay()->toDateString(),
                'note' => 'Publicidad a tasa cost÷max(revenue ML, GMV local).',
            ],
        ]);

        $this->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Index')
                ->has('orders.data', 2)
                ->where('orders.data', function ($rows) {
                    $byExternal = collect($rows)->keyBy('external_order_id');

                    return ($byExternal['ORD-ADS-1']['has_ads_attribution'] ?? false) === true
                        && (float) ($byExternal['ORD-ADS-1']['ads_allocated_amount'] ?? 0) === 42.5
                        && ($byExternal['ORD-ADS-NONE']['has_ads_attribution'] ?? true) === false;
                }));
    }

    #[Test]
    public function order_show_includes_ads_attribution_summary(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithConnection();
        [$order, $line] = $this->seedOrder($workspace, $connection);

        FinancialEvent::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'order_line_id' => $line->id,
            'event_type' => AttributeAdvertisingToOrders::EVENT_ALLOCATED,
            'stage' => 'expected',
            'amount' => '-12.000000',
            'currency_code' => 'MXN',
            'reporting_amount' => '-12.000000',
            'reporting_currency' => 'MXN',
            'occurred_at' => $order->ordered_at,
            'provenance' => [
                'source' => 'product_ads_item_daily',
                'model' => 'blended_acos_ml_rate',
                'ads_cost' => 40,
                'ml_revenue' => 200,
                'item_gmv' => 500,
                'denom' => 500,
                'coverage_ratio' => 0.4,
                'rate' => 0.024,
                'ml_item_id' => 'MLM111',
                'date' => now()->subDay()->toDateString(),
                'note' => 'Publicidad a tasa cost÷max(revenue ML, GMV local).',
            ],
        ]);

        $this->getJson(route('orders.show', $order))
            ->assertOk()
            ->assertJsonPath('ads_attribution.has_ads', true)
            ->assertJsonPath('ads_attribution.total_amount', 12)
            ->assertJsonPath('ads_attribution.events.0.ml_item_id', 'MLM111')
            ->assertJsonPath('ads_attribution.events.0.rate', 0.024)
            ->assertJsonPath('ads_attribution.events.0.coverage_ratio', 0.4);
    }

    #[Test]
    public function order_show_without_ads_has_empty_attribution(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithConnection();
        [$order] = $this->seedOrder($workspace, $connection);

        $this->getJson(route('orders.show', $order))
            ->assertOk()
            ->assertJsonPath('ads_attribution.has_ads', false)
            ->assertJsonPath('ads_attribution.total_amount', 0)
            ->assertJsonPath('ads_attribution.events', []);
    }
}
