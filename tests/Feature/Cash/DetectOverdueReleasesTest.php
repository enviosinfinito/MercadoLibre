<?php

namespace Tests\Feature\Cash;

use App\Domain\Cash\Actions\BuildOverdueReleaseCoverage;
use App\Domain\Cash\Actions\DetectOverdueReleases;
use App\Domain\Cash\Support\CashReportDateWindows;
use App\Domain\Cash\Support\OverdueReleaseQuery;
use App\Domain\PostSale\Actions\ResolveOrderPostSaleOutcome;
use App\Models\CashLedgerEntry;
use App\Models\CashReconciliationLink;
use App\Models\CashReportFile;
use App\Models\Connection;
use App\Models\MarketplacePayment;
use App\Models\Order;
use App\Models\ProfitSnapshot;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DetectOverdueReleasesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function delivered_without_payment_after_grace_is_orphan(): void
    {
        [$workspace, $connection] = $this->seedWorkspace();
        $order = $this->seedDeliveredOrder($workspace, $connection, daysAgo: 3);

        $result = app(DetectOverdueReleases::class)->execute($workspace->id);
        $rows = collect($result['paginator']->items());

        $this->assertCount(1, $rows);
        $this->assertSame($order->id, $rows[0]['order_id']);
        $this->assertSame(OverdueReleaseQuery::KIND_ORPHAN, $rows[0]['kind']);
        $this->assertSame('83.000000', $rows[0]['expected_net_amount']);
        $this->assertSame(1, $result['kpis']['orphan']);
        $this->assertSame(1, $result['kpis']['overdue']);
        $this->assertSame(0, $result['kpis']['held']);
        $this->assertSame('estimated', $rows[0]['expected_release_source']);
        $delivered = $order->shipments()->first()?->delivered_at;
        $this->assertNotNull($delivered);
        $this->assertEquals(
            $delivered->copy()->addDays(2)->timestamp,
            Carbon::parse($rows[0]['expected_release_at'])->timestamp,
        );
    }

    #[Test]
    public function delivered_with_unreleased_payment_is_unreleased(): void
    {
        [$workspace, $connection] = $this->seedWorkspace();
        $order = $this->seedDeliveredOrder($workspace, $connection, daysAgo: 3);
        $this->seedPayment($order, [
            'is_released' => false,
            'money_release_at' => now()->subDay(),
            'net_received_amount' => '80.000000',
        ]);

        $result = app(DetectOverdueReleases::class)->execute($workspace->id);
        $rows = collect($result['paginator']->items());

        $this->assertCount(1, $rows);
        $this->assertSame(OverdueReleaseQuery::KIND_UNRELEASED, $rows[0]['kind']);
        $this->assertSame('80.000000', $rows[0]['expected_net_amount']);
        $this->assertSame(1, $result['kpis']['unreleased']);
        $this->assertSame(1, $result['kpis']['overdue']);
        $this->assertSame('ml', $rows[0]['expected_release_source']);
        $this->assertEquals(
            now()->subDay()->timestamp,
            Carbon::parse($rows[0]['expected_release_at'])->timestamp,
        );
    }

    #[Test]
    public function released_payment_is_not_listed(): void
    {
        [$workspace, $connection] = $this->seedWorkspace();
        $order = $this->seedDeliveredOrder($workspace, $connection, daysAgo: 3);
        $this->seedPayment($order, ['is_released' => true, 'money_release_at' => now()->subDay()]);

        $result = app(DetectOverdueReleases::class)->execute($workspace->id);

        $this->assertCount(0, $result['paginator']->items());
        $this->assertSame(0, $result['kpis']['overdue']);
    }

    #[Test]
    public function ledger_release_excludes_order_even_without_is_released_flag(): void
    {
        [$workspace, $connection] = $this->seedWorkspace();
        $order = $this->seedDeliveredOrder($workspace, $connection, daysAgo: 3);
        $this->seedPayment($order, ['is_released' => false]);

        CashLedgerEntry::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'entry_type' => 'release',
            'transaction_type' => 'RELEASE',
            'external_order_id' => $order->external_order_id,
            'net_amount' => '83.000000',
            'currency_code' => 'MXN',
            'occurred_at' => now()->subDay(),
            'released_at' => now()->subDay(),
            'is_released' => true,
            'provenance' => 'mp_release_report',
            'idempotency_key' => 'rel|'.$order->external_order_id,
            'payload' => [],
        ]);

        $result = app(DetectOverdueReleases::class)->execute($workspace->id);

        $this->assertCount(0, $result['paginator']->items());
    }

    #[Test]
    public function reconciliation_link_to_released_ledger_excludes_order(): void
    {
        [$workspace, $connection] = $this->seedWorkspace();
        $order = $this->seedDeliveredOrder($workspace, $connection, daysAgo: 3);
        $payment = $this->seedPayment($order, ['is_released' => false]);

        $entry = CashLedgerEntry::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'entry_type' => 'settlement',
            'transaction_type' => 'SETTLEMENT',
            'external_source_id' => 'pay-link-1',
            'external_order_id' => 'other-ref',
            'net_amount' => '83.000000',
            'currency_code' => 'MXN',
            'occurred_at' => now()->subDay(),
            'released_at' => now()->subDay(),
            'is_released' => true,
            'provenance' => 'mp_settlement_report',
            'idempotency_key' => 'set|link|'.$order->id,
            'payload' => [],
        ]);

        CashReconciliationLink::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'cash_ledger_entry_id' => $entry->id,
            'order_id' => $order->id,
            'marketplace_payment_id' => $payment->id,
            'allocated_amount' => '83.000000',
            'expected_amount' => '83.000000',
            'diff_amount' => '0.000000',
            'match_method' => 'source_id',
            'status' => 'matched',
        ]);

        $result = app(DetectOverdueReleases::class)->execute($workspace->id);

        $this->assertCount(0, $result['paginator']->items());
    }

    #[Test]
    public function future_money_release_date_is_not_overdue(): void
    {
        [$workspace, $connection] = $this->seedWorkspace();
        $order = $this->seedDeliveredOrder($workspace, $connection, daysAgo: 3);
        $this->seedPayment($order, [
            'is_released' => false,
            'money_release_at' => now()->addHours(6),
        ]);

        $result = app(DetectOverdueReleases::class)->execute($workspace->id);

        $this->assertCount(0, $result['paginator']->items());
    }

    #[Test]
    public function delivered_yesterday_is_within_grace(): void
    {
        [$workspace, $connection] = $this->seedWorkspace();
        $this->seedDeliveredOrder($workspace, $connection, daysAgo: 1);

        $result = app(DetectOverdueReleases::class)->execute($workspace->id);

        $this->assertCount(0, $result['paginator']->items());
    }

    #[Test]
    public function returned_and_cancelled_orders_are_excluded(): void
    {
        [$workspace, $connection] = $this->seedWorkspace();
        $returned = $this->seedDeliveredOrder($workspace, $connection, daysAgo: 4, extra: [
            'post_sale_outcome' => ResolveOrderPostSaleOutcome::RETURNED,
        ]);
        $cancelled = $this->seedDeliveredOrder($workspace, $connection, daysAgo: 4, extra: [
            'status' => 'cancelled',
            'cancelled_at' => now()->subDay(),
        ]);

        $result = app(DetectOverdueReleases::class)->execute($workspace->id);
        $ids = collect($result['paginator']->items())->pluck('order_id')->all();

        $this->assertNotContains($returned->id, $ids);
        $this->assertNotContains($cancelled->id, $ids);
        $this->assertCount(0, $ids);
    }

    #[Test]
    public function in_mediation_is_classified_as_held(): void
    {
        [$workspace, $connection] = $this->seedWorkspace();
        $order = $this->seedDeliveredOrder($workspace, $connection, daysAgo: 3);
        $this->seedPayment($order, [
            'status' => 'in_mediation',
            'is_released' => false,
            'money_release_at' => now()->subDay(),
        ]);

        $all = app(DetectOverdueReleases::class)->execute($workspace->id);
        $this->assertCount(1, $all['paginator']->items());
        $this->assertSame(OverdueReleaseQuery::KIND_HELD, $all['paginator']->items()[0]['kind']);
        $this->assertSame(1, $all['kpis']['held']);
        $this->assertSame(0, $all['kpis']['overdue']);

        $failures = app(DetectOverdueReleases::class)->execute(
            $workspace->id,
            kind: OverdueReleaseQuery::KIND_OVERDUE,
        );
        $this->assertCount(0, $failures['paginator']->items());

        $held = app(DetectOverdueReleases::class)->execute(
            $workspace->id,
            kind: OverdueReleaseQuery::KIND_HELD,
        );
        $this->assertCount(1, $held['paginator']->items());
        $this->assertSame($order->id, $held['paginator']->items()[0]['order_id']);
    }

    #[Test]
    public function orders_index_filters_overdue_release_issue(): void
    {
        [$user, $workspace, $connection] = $this->actingMember();
        $orphan = $this->seedDeliveredOrder($workspace, $connection, daysAgo: 3);
        $fresh = $this->seedDeliveredOrder($workspace, $connection, daysAgo: 1);
        $paid = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-PAID-'.uniqid(),
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '50.000000',
            'paid_at' => now(),
            'ordered_at' => now(),
        ]);

        $this->get(route('orders.index', ['release_issue' => 'overdue']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Index')
                ->where('overdue_release_count', 1)
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $orphan->id)
                ->where('orders.data.0.release_issue', OverdueReleaseQuery::KIND_ORPHAN)
                ->where('orders.data.0.expected_release_source', 'estimated')
                ->where('filters.release_issue', 'overdue')
                ->where('release_coverage.overdue_count', 1)
                ->has('release_coverage.gaps'));

        $this->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('overdue_release_count', 1)
                ->has('orders.data', 3));

        $this->assertNotNull($fresh->id);
        $this->assertNotNull($paid->id);
    }

    #[Test]
    public function cash_index_overdue_tab_renders(): void
    {
        [$user, $workspace, $connection] = $this->actingMember();
        $order = $this->seedDeliveredOrder($workspace, $connection, daysAgo: 3);
        $this->seedPayment($order, [
            'is_released' => false,
            'money_release_at' => now()->subDay(),
            'net_received_amount' => '77.500000',
        ]);

        $this->get(route('finance.cash.index', ['view' => 'overdue']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/Cash/Index')
                ->where('view', 'overdue')
                ->where('overdue_count', 1)
                ->has('rows.data', 1)
                ->where('rows.data.0.order_id', $order->id)
                ->where('rows.data.0.kind', OverdueReleaseQuery::KIND_UNRELEASED)
                ->where('rows.data.0.expected_release_source', 'ml')
                ->where('overdue_kpis.unreleased', 1)
                ->where('overdue_kpis.overdue_net_total', '77.500000')
                ->has('release_coverage'));
    }

    #[Test]
    public function coverage_detects_gap_when_release_reports_missing(): void
    {
        [$workspace, $connection] = $this->seedWorkspace();
        $this->seedDeliveredOrder($workspace, $connection, daysAgo: 10);

        $coverage = app(BuildOverdueReleaseCoverage::class)->execute($workspace->id, $connection->id);

        $this->assertSame(1, $coverage['overdue_count']);
        $this->assertNotNull($coverage['tentative_from']);
        $this->assertNotNull($coverage['tentative_to']);
        $this->assertNull($coverage['covered_from']);
        $this->assertNotEmpty($coverage['gaps']);
        $this->assertNotNull($coverage['sync_from']);
        $this->assertNotNull($coverage['sync_to']);
    }

    #[Test]
    public function coverage_shrinks_gap_when_release_report_file_exists(): void
    {
        [$workspace, $connection] = $this->seedWorkspace();
        $old = $this->seedDeliveredOrder($workspace, $connection, daysAgo: 20);
        $recent = $this->seedDeliveredOrder($workspace, $connection, daysAgo: 4);
        $recentDelivered = $recent->shipments()->first()?->delivered_at;
        $this->assertNotNull($recentDelivered);
        $recentTentative = CarbonImmutable::parse($recentDelivered)->addDays(2);

        CashReportFile::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'report_kind' => 'release',
            'remote_file_name' => 'partial.csv',
            'storage_path' => 'cash-reports/partial.csv',
            'report_shape' => 'full',
            'rows_count' => 1,
            'bytes' => 10,
            'begin_date' => $recentTentative->subDays(1)->startOfDay(),
            'end_date' => $recentTentative->endOfDay(),
            'report_id' => '1',
        ]);

        $coverage = app(BuildOverdueReleaseCoverage::class)->execute($workspace->id, $connection->id);

        $this->assertSame(2, $coverage['overdue_count']);
        $this->assertNotNull($coverage['covered_from']);
        $this->assertNotEmpty($coverage['gaps']);
        $this->assertTrue(
            CarbonImmutable::parse($coverage['gaps'][0]['to'])
                ->lt(CarbonImmutable::parse($coverage['covered_from'])->addDay()),
        );
        $this->assertNotNull($old->id);
    }

    #[Test]
    public function date_windows_chunk_inclusive_range(): void
    {
        $from = CarbonImmutable::parse('2026-08-01', 'America/Mexico_City')->startOfDay();
        $to = CarbonImmutable::parse('2026-08-05', 'America/Mexico_City')->startOfDay();
        $windows = CashReportDateWindows::chunk($from, $to, 2, 'America/Mexico_City');

        $this->assertCount(3, $windows);
        $this->assertSame('2026-08-01', $windows[0][0]->toDateString());
        $this->assertSame('2026-08-02', $windows[0][1]->toDateString());
        $this->assertSame('2026-08-05', $windows[2][0]->toDateString());
        $this->assertSame('2026-08-05', $windows[2][1]->toDateString());
    }

    /**
     * @return array{0: Workspace, 1: Connection}
     */
    private function seedWorkspace(): array
    {
        $workspace = Workspace::factory()->create();
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);

        return [$workspace, $connection];
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Connection}
     */
    private function actingMember(): array
    {
        $user = User::factory()->create();
        [$workspace, $connection] = $this->seedWorkspace();
        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);
        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$user, $workspace, $connection];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function seedDeliveredOrder(
        Workspace $workspace,
        Connection $connection,
        int $daysAgo,
        array $extra = [],
    ): Order {
        $order = Order::query()->create(array_merge([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => '20000'.substr(uniqid(), -8),
            'status' => 'delivered',
            'currency_code' => 'MXN',
            'total_amount' => '100.000000',
            'paid_at' => now()->subDays($daysAgo + 2),
            'ordered_at' => now()->subDays($daysAgo + 3),
        ], $extra));

        Shipment::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'external_shipment_id' => 'SH-'.$order->id,
            'status' => 'delivered',
            'delivered_at' => now()->subDays($daysAgo),
        ]);

        ProfitSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'order_id' => $order->id,
            'stage' => 'expected',
            'revenue_amount' => '100.000000',
            'fees_amount' => '12.000000',
            'cogs_amount' => '0.000000',
            'profit_amount' => '83.000000',
            'currency_code' => 'MXN',
            'is_incomplete' => false,
            'payload' => [
                'net_received_amount' => '83.000000',
                'marketplace_net_amount' => '83.000000',
            ],
        ]);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function seedPayment(Order $order, array $attrs = []): MarketplacePayment
    {
        return MarketplacePayment::query()->create(array_merge([
            'workspace_id' => $order->workspace_id,
            'connection_id' => $order->connection_id,
            'order_id' => $order->id,
            'external_payment_id' => 'pay-'.$order->id.'-'.uniqid(),
            'status' => 'approved',
            'transaction_amount' => '100.000000',
            'marketplace_fee_amount' => '12.000000',
            'net_received_amount' => '83.000000',
            'expected_net_amount' => '83.000000',
            'currency_code' => 'MXN',
            'paid_at' => now()->subDays(5),
            'is_released' => false,
            'reconciliation_status' => 'pending',
        ], $attrs));
    }
}
