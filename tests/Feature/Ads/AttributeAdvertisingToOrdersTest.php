<?php

namespace Tests\Feature\Ads;

use App\Domain\Ads\Actions\AttributeAdvertisingToOrders;
use App\Domain\Shared\Support\TenantContext;
use App\Models\AdAdvertiser;
use App\Models\AdCampaign;
use App\Models\AdSpendDaily;
use App\Models\Connection;
use App\Models\FinancialEvent;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AttributeAdvertisingToOrdersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function allocates_at_ml_acos_rate_and_is_idempotent(): void
    {
        $workspace = Workspace::factory()->create();
        TenantContext::set($workspace->id);
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);

        $advertiser = AdAdvertiser::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_advertiser_id' => 'adv-1',
            'site_id' => 'MLM',
            'name' => 'Adv',
        ]);
        $campaign = AdCampaign::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'ad_advertiser_id' => $advertiser->id,
            'external_campaign_id' => 'camp-1',
            'name' => 'Camp',
        ]);

        $day = now()->subDay()->startOfDay();

        AdSpendDaily::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'ad_advertiser_id' => $advertiser->id,
            'ad_campaign_id' => $campaign->id,
            'external_campaign_id' => 'camp-1',
            'ml_item_id' => 'MLM123',
            'date' => $day->toDateString(),
            'cost' => '20.000000',
            'clicks' => 10,
            'prints' => 100,
            'total_amount' => '100.000000',
            'currency_code' => 'MXN',
        ]);

        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-ADS-1',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '100.000000',
            'ordered_at' => $day->copy()->setTime(12, 0),
        ]);

        OrderLine::query()->create([
            'workspace_id' => $workspace->id,
            'order_id' => $order->id,
            'connection_id' => $connection->id,
            'external_item_id' => 'MLM123',
            'sku' => 'SKU-1',
            'title' => 'Item',
            'quantity' => '1',
            'unit_price_amount' => '100.000000',
            'currency_code' => 'MXN',
            'line_total_amount' => '100.000000',
            'match_status' => 'matched',
        ]);

        $action = app(AttributeAdvertisingToOrders::class);

        $first = $action->execute((int) $workspace->id, [
            'date_from' => $day->toDateString(),
            'date_to' => $day->toDateString(),
            'connection_id' => $connection->id,
            'refresh_profit' => true,
        ]);

        $this->assertSame(1, $first['events_written']);
        $this->assertSame(1, $first['orders_touched']);
        $this->assertSame(0, $first['unallocated_events']);

        $event = FinancialEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', 'expected_advertising')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('-20.000000', (string) $event->amount);
        $this->assertSame('blended_acos_ml_rate', $event->provenance['model'] ?? null);
        $this->assertSame('0.20000000', $event->provenance['rate'] ?? null);

        $second = $action->execute((int) $workspace->id, [
            'date_from' => $day->toDateString(),
            'date_to' => $day->toDateString(),
            'connection_id' => $connection->id,
            'refresh_profit' => true,
        ]);

        $this->assertSame(1, $second['events_written']);
        $this->assertSame(
            1,
            FinancialEvent::query()
                ->where('order_id', $order->id)
                ->where('event_type', 'expected_advertising')
                ->count(),
        );
    }

    #[Test]
    public function does_not_dump_full_spend_on_partial_local_gmv(): void
    {
        $workspace = Workspace::factory()->create();
        TenantContext::set($workspace->id);
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);

        $advertiser = AdAdvertiser::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_advertiser_id' => 'adv-2',
            'site_id' => 'MLM',
            'name' => 'Adv',
        ]);
        $campaign = AdCampaign::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'ad_advertiser_id' => $advertiser->id,
            'external_campaign_id' => 'camp-2',
            'name' => 'Camp',
        ]);

        $day = now()->subDay()->startOfDay();

        // ML: $2535 spend on $18162 attributed revenue (~14% ACoS)
        AdSpendDaily::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'ad_advertiser_id' => $advertiser->id,
            'ad_campaign_id' => $campaign->id,
            'external_campaign_id' => 'camp-2',
            'ml_item_id' => 'MLM999',
            'date' => $day->toDateString(),
            'cost' => '2535.000000',
            'clicks' => 100,
            'prints' => 1000,
            'total_amount' => '18162.000000',
            'currency_code' => 'MXN',
        ]);

        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-PARTIAL',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '1222.000000',
            'ordered_at' => $day->copy()->setTime(12, 0),
        ]);

        OrderLine::query()->create([
            'workspace_id' => $workspace->id,
            'order_id' => $order->id,
            'connection_id' => $connection->id,
            'external_item_id' => 'MLM999',
            'sku' => 'SKU-P',
            'title' => 'Partial',
            'quantity' => '3',
            'unit_price_amount' => '407.333333',
            'currency_code' => 'MXN',
            'line_total_amount' => '1222.000000',
            'match_status' => 'matched',
        ]);

        $result = app(AttributeAdvertisingToOrders::class)->execute((int) $workspace->id, [
            'date_from' => $day->toDateString(),
            'date_to' => $day->toDateString(),
            'connection_id' => $connection->id,
            'refresh_profit' => false,
        ]);

        $allocated = FinancialEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', AttributeAdvertisingToOrders::EVENT_ALLOCATED)
            ->sum(\DB::raw('ABS(amount)'));

        $residual = FinancialEvent::query()
            ->whereNull('order_id')
            ->where('event_type', AttributeAdvertisingToOrders::EVENT_UNALLOCATED)
            ->sum(\DB::raw('ABS(amount)'));

        // Fair share ≈ 1222/18162 * 2535 ≈ 170.55 (NOT ~641 from old algorithm)
        $this->assertEqualsWithDelta(170.55, (float) $allocated, 0.5);
        $this->assertEqualsWithDelta(2535 - 170.55, (float) $residual, 0.5);
        $this->assertSame(1, $result['unallocated_events']);
        $this->assertLessThan(300, (float) $allocated, 'Must not dump full spend on partial local GMV');
    }

    #[Test]
    public function local_gmv_heavier_than_ml_does_not_over_allocate_spend(): void
    {
        $workspace = Workspace::factory()->create();
        TenantContext::set($workspace->id);
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);

        $advertiser = AdAdvertiser::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_advertiser_id' => 'adv-3',
            'site_id' => 'MLM',
            'name' => 'Adv',
        ]);
        $campaign = AdCampaign::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'ad_advertiser_id' => $advertiser->id,
            'external_campaign_id' => 'camp-3',
            'name' => 'Camp',
        ]);

        $day = now()->subDay()->startOfDay();

        // ML: $100 spend on $1000 attributed (~10% ACoS). Local GMV 4× larger (orgánico).
        AdSpendDaily::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'ad_advertiser_id' => $advertiser->id,
            'ad_campaign_id' => $campaign->id,
            'external_campaign_id' => 'camp-3',
            'ml_item_id' => 'MLM777',
            'date' => $day->toDateString(),
            'cost' => '100.000000',
            'clicks' => 50,
            'prints' => 500,
            'total_amount' => '1000.000000',
            'currency_code' => 'MXN',
        ]);

        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-HEAVY',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '4000.000000',
            'ordered_at' => $day->copy()->setTime(12, 0),
        ]);

        OrderLine::query()->create([
            'workspace_id' => $workspace->id,
            'order_id' => $order->id,
            'connection_id' => $connection->id,
            'external_item_id' => 'MLM777',
            'sku' => 'SKU-H',
            'title' => 'Heavy',
            'quantity' => '4',
            'unit_price_amount' => '1000.000000',
            'currency_code' => 'MXN',
            'line_total_amount' => '4000.000000',
            'match_status' => 'matched',
        ]);

        $result = app(AttributeAdvertisingToOrders::class)->execute((int) $workspace->id, [
            'date_from' => $day->toDateString(),
            'date_to' => $day->toDateString(),
            'connection_id' => $connection->id,
            'refresh_profit' => false,
        ]);

        $allocated = FinancialEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', AttributeAdvertisingToOrders::EVENT_ALLOCATED)
            ->sum(\DB::raw('ABS(amount)'));

        $residual = FinancialEvent::query()
            ->whereNull('order_id')
            ->where('event_type', AttributeAdvertisingToOrders::EVENT_UNALLOCATED)
            ->sum(\DB::raw('ABS(amount)'));

        // rate = 100/4000 = 0.025 → allocated = 100 (NOT 400 from old ML ACoS on all local GMV)
        $this->assertEqualsWithDelta(100.0, (float) $allocated, 0.05);
        $this->assertEqualsWithDelta(0.0, (float) $residual, 0.05);
        $this->assertSame(0, $result['unallocated_events']);

        $event = FinancialEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', AttributeAdvertisingToOrders::EVENT_ALLOCATED)
            ->first();
        $this->assertNotNull($event);
        $this->assertEqualsWithDelta(0.025, (float) ($event->provenance['rate'] ?? 0), 0.0001);
    }

    #[Test]
    public function zero_ml_revenue_keeps_spend_as_residual_not_on_orders(): void
    {
        [$workspace, $connection, $day] = $this->seedWorkspace();

        $this->seedSpend($workspace, $connection, 'MLM5218', $day->toDateString(), '32.350000', '0.000000');
        $order = $this->seedOrderWithLine($workspace, $connection, 'ORD-ZERO-ML', 'MLM5218', '407.380000', $day);

        $result = app(AttributeAdvertisingToOrders::class)->execute((int) $workspace->id, [
            'date_from' => $day->toDateString(),
            'date_to' => $day->toDateString(),
            'connection_id' => $connection->id,
            'refresh_profit' => false,
        ]);

        $allocated = (float) FinancialEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', AttributeAdvertisingToOrders::EVENT_ALLOCATED)
            ->sum(\DB::raw('ABS(amount)'));

        $residual = (float) FinancialEvent::query()
            ->whereNull('order_id')
            ->where('event_type', AttributeAdvertisingToOrders::EVENT_UNALLOCATED)
            ->sum(\DB::raw('ABS(amount)'));

        $this->assertEqualsWithDelta(0.0, $allocated, 0.01);
        $this->assertEqualsWithDelta(32.35, $residual, 0.01);
        $this->assertSame(0, $result['events_written']);
        $this->assertSame(1, $result['unallocated_events']);
        $this->assertEqualsWithDelta(32.35, $allocated + $residual, 0.01);
    }

    #[Test]
    public function order_id_path_dilutes_across_sibling_orders_same_item_day(): void
    {
        [$workspace, $connection, $day] = $this->seedWorkspace();

        // $100 cost, $1000 ML revenue; two local orders $1000 each → denom=2000, rate=0.05
        $this->seedSpend($workspace, $connection, 'MLM-SIB', $day->toDateString(), '100.000000', '1000.000000');
        $orderA = $this->seedOrderWithLine($workspace, $connection, 'ORD-SIB-A', 'MLM-SIB', '1000.000000', $day);
        $orderB = $this->seedOrderWithLine($workspace, $connection, 'ORD-SIB-B', 'MLM-SIB', '1000.000000', $day);

        $result = app(AttributeAdvertisingToOrders::class)->execute((int) $workspace->id, [
            'date_from' => $day->toDateString(),
            'date_to' => $day->toDateString(),
            'connection_id' => $connection->id,
            'order_id' => (int) $orderA->id,
            'refresh_profit' => false,
        ]);

        $allocA = (float) FinancialEvent::query()
            ->where('order_id', $orderA->id)
            ->where('event_type', AttributeAdvertisingToOrders::EVENT_ALLOCATED)
            ->sum(\DB::raw('ABS(amount)'));
        $allocB = (float) FinancialEvent::query()
            ->where('order_id', $orderB->id)
            ->where('event_type', AttributeAdvertisingToOrders::EVENT_ALLOCATED)
            ->sum(\DB::raw('ABS(amount)'));
        $residual = (float) FinancialEvent::query()
            ->whereNull('order_id')
            ->where('event_type', AttributeAdvertisingToOrders::EVENT_UNALLOCATED)
            ->sum(\DB::raw('ABS(amount)'));

        $this->assertEqualsWithDelta(50.0, $allocA, 0.05);
        $this->assertEqualsWithDelta(50.0, $allocB, 0.05);
        $this->assertEqualsWithDelta(0.0, $residual, 0.05);
        $this->assertEqualsWithDelta(100.0, $allocA + $allocB + $residual, 0.05);
        $this->assertSame(2, $result['events_written']);
    }

    #[Test]
    public function without_spend_does_not_wipe_existing_allocation(): void
    {
        [$workspace, $connection, $day] = $this->seedWorkspace();
        $order = $this->seedOrderWithLine($workspace, $connection, 'ORD-KEEP', 'MLM-KEEP', '100.000000', $day);

        FinancialEvent::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'order_line_id' => $order->lines()->first()->id,
            'event_type' => AttributeAdvertisingToOrders::EVENT_ALLOCATED,
            'stage' => 'expected',
            'amount' => '-5.000000',
            'currency_code' => 'MXN',
            'reporting_amount' => '-5.000000',
            'reporting_currency' => 'MXN',
            'occurred_at' => $day->copy()->setTime(12, 0),
            'provenance' => [
                'model' => AttributeAdvertisingToOrders::MODEL,
                'source' => AttributeAdvertisingToOrders::SOURCE,
                'ml_item_id' => 'MLM-KEEP',
                'date' => $day->toDateString(),
            ],
        ]);

        $result = app(AttributeAdvertisingToOrders::class)->execute((int) $workspace->id, [
            'date_from' => $day->toDateString(),
            'date_to' => $day->toDateString(),
            'connection_id' => $connection->id,
            'order_id' => (int) $order->id,
            'refresh_profit' => false,
        ]);

        $this->assertSame(0, $result['events_written']);
        $this->assertSame(
            1,
            FinancialEvent::query()
                ->where('order_id', $order->id)
                ->where('event_type', AttributeAdvertisingToOrders::EVENT_ALLOCATED)
                ->count(),
        );
    }

    #[Test]
    public function cancelled_orders_are_excluded_from_gmv_and_allocation(): void
    {
        [$workspace, $connection, $day] = $this->seedWorkspace();

        $this->seedSpend($workspace, $connection, 'MLM-CAN', $day->toDateString(), '100.000000', '1000.000000');
        $paid = $this->seedOrderWithLine($workspace, $connection, 'ORD-CAN-PAID', 'MLM-CAN', '1000.000000', $day, 'paid');
        $this->seedOrderWithLine($workspace, $connection, 'ORD-CAN-X', 'MLM-CAN', '1000.000000', $day, 'cancelled');

        app(AttributeAdvertisingToOrders::class)->execute((int) $workspace->id, [
            'date_from' => $day->toDateString(),
            'date_to' => $day->toDateString(),
            'connection_id' => $connection->id,
            'refresh_profit' => false,
        ]);

        $allocPaid = (float) FinancialEvent::query()
            ->where('order_id', $paid->id)
            ->where('event_type', AttributeAdvertisingToOrders::EVENT_ALLOCATED)
            ->sum(\DB::raw('ABS(amount)'));

        $cancelled = Order::query()->where('external_order_id', 'ORD-CAN-X')->first();
        $allocCancelled = $cancelled === null ? 0.0 : (float) FinancialEvent::query()
            ->where('order_id', $cancelled->id)
            ->where('event_type', AttributeAdvertisingToOrders::EVENT_ALLOCATED)
            ->sum(\DB::raw('ABS(amount)'));

        // denom = max(1000 ML, 1000 local) = 1000 → full $100 on paid order
        $this->assertEqualsWithDelta(100.0, $allocPaid, 0.05);
        $this->assertEqualsWithDelta(0.0, $allocCancelled, 0.05);
    }

    #[Test]
    public function bulk_reattr_repairs_over_allocation_from_partial_gmv(): void
    {
        [$workspace, $connection, $day] = $this->seedWorkspace();

        $this->seedSpend($workspace, $connection, 'MLM-FIX', $day->toDateString(), '100.000000', '1000.000000');
        $orderA = $this->seedOrderWithLine($workspace, $connection, 'ORD-FIX-A', 'MLM-FIX', '1000.000000', $day);
        $orderB = $this->seedOrderWithLine($workspace, $connection, 'ORD-FIX-B', 'MLM-FIX', '1000.000000', $day);

        // Corrupt: dump full cost on A as if it were the only order.
        FinancialEvent::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $orderA->id,
            'order_line_id' => $orderA->lines()->first()->id,
            'event_type' => AttributeAdvertisingToOrders::EVENT_ALLOCATED,
            'stage' => 'expected',
            'amount' => '-100.000000',
            'currency_code' => 'MXN',
            'reporting_amount' => '-100.000000',
            'reporting_currency' => 'MXN',
            'occurred_at' => $day->copy()->setTime(12, 0),
            'provenance' => [
                'model' => AttributeAdvertisingToOrders::MODEL,
                'source' => AttributeAdvertisingToOrders::SOURCE,
                'ml_item_id' => 'MLM-FIX',
                'date' => $day->toDateString(),
            ],
        ]);

        app(AttributeAdvertisingToOrders::class)->execute((int) $workspace->id, [
            'date_from' => $day->toDateString(),
            'date_to' => $day->toDateString(),
            'connection_id' => $connection->id,
            'refresh_profit' => false,
        ]);

        $allocA = (float) FinancialEvent::query()
            ->where('order_id', $orderA->id)
            ->where('event_type', AttributeAdvertisingToOrders::EVENT_ALLOCATED)
            ->sum(\DB::raw('ABS(amount)'));
        $allocB = (float) FinancialEvent::query()
            ->where('order_id', $orderB->id)
            ->where('event_type', AttributeAdvertisingToOrders::EVENT_ALLOCATED)
            ->sum(\DB::raw('ABS(amount)'));
        $residual = (float) FinancialEvent::query()
            ->whereNull('order_id')
            ->where('event_type', AttributeAdvertisingToOrders::EVENT_UNALLOCATED)
            ->where('provenance->ml_item_id', 'MLM-FIX')
            ->sum(\DB::raw('ABS(amount)'));

        $this->assertEqualsWithDelta(50.0, $allocA, 0.05);
        $this->assertEqualsWithDelta(50.0, $allocB, 0.05);
        $this->assertEqualsWithDelta(100.0, $allocA + $allocB + $residual, 0.05);
    }

    /**
     * @return array{0: Workspace, 1: Connection, 2: \Illuminate\Support\Carbon}
     */
    private function seedWorkspace(): array
    {
        $workspace = Workspace::factory()->create();
        TenantContext::set($workspace->id);
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);
        $day = now()->subDay()->startOfDay();

        return [$workspace, $connection, $day];
    }

    private function seedSpend(
        Workspace $workspace,
        Connection $connection,
        string $mlItemId,
        string $date,
        string $cost,
        string $mlRevenue,
    ): void {
        $advertiser = AdAdvertiser::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_advertiser_id' => 'adv-'.$mlItemId,
            'site_id' => 'MLM',
            'name' => 'Adv',
        ]);
        $campaign = AdCampaign::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'ad_advertiser_id' => $advertiser->id,
            'external_campaign_id' => 'camp-'.$mlItemId,
            'name' => 'Camp',
        ]);

        AdSpendDaily::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'ad_advertiser_id' => $advertiser->id,
            'ad_campaign_id' => $campaign->id,
            'external_campaign_id' => 'camp-'.$mlItemId,
            'ml_item_id' => $mlItemId,
            'date' => $date,
            'cost' => $cost,
            'clicks' => 10,
            'prints' => 100,
            'total_amount' => $mlRevenue,
            'currency_code' => 'MXN',
        ]);
    }

    private function seedOrderWithLine(
        Workspace $workspace,
        Connection $connection,
        string $externalOrderId,
        string $mlItemId,
        string $lineTotal,
        \Illuminate\Support\Carbon $day,
        string $status = 'paid',
    ): Order {
        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => $externalOrderId,
            'status' => $status,
            'currency_code' => 'MXN',
            'total_amount' => $lineTotal,
            'ordered_at' => $day->copy()->setTime(12, 0),
        ]);

        OrderLine::query()->create([
            'workspace_id' => $workspace->id,
            'order_id' => $order->id,
            'connection_id' => $connection->id,
            'external_item_id' => $mlItemId,
            'sku' => 'SKU-'.$mlItemId,
            'title' => 'Item',
            'quantity' => '1',
            'unit_price_amount' => $lineTotal,
            'currency_code' => 'MXN',
            'line_total_amount' => $lineTotal,
            'match_status' => 'matched',
        ]);

        return $order;
    }
}
