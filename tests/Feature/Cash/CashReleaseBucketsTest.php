<?php

namespace Tests\Feature\Cash;

use App\Domain\Cash\Actions\BuildCashReleaseBucketDetail;
use App\Domain\Cash\Actions\BuildCashReleaseBuckets;
use App\Domain\Cash\Actions\IngestMercadoPagoReportRows;
use App\Models\CashLedgerEntry;
use App\Models\Connection;
use App\Models\MarketplacePayment;
use App\Models\Order;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CashReleaseBucketsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function payouts_transaction_type_ingests_as_withdrawal(): void
    {
        $workspace = Workspace::factory()->create();
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);

        $stats = app(IngestMercadoPagoReportRows::class)->execute($connection, 'settlement', [[
            'TRANSACTION_TYPE' => 'PAYOUTS',
            'SOURCE_ID' => 'payout-real-1',
            'EXTERNAL_REFERENCE' => 'ref-1',
            'SETTLEMENT_NET_AMOUNT' => '-150000.00',
            'SETTLEMENT_DATE' => '2026-08-06T23:17:58Z',
            'DESCRIPTION' => 'Bank transfer',
        ]]);

        $this->assertSame(1, $stats['created']);
        $this->assertDatabaseHas('cash_ledger_entries', [
            'connection_id' => $connection->id,
            'external_source_id' => 'payout-real-1',
            'entry_type' => 'withdrawal',
            'transaction_type' => 'PAYOUTS',
        ]);
    }

    #[Test]
    public function release_buckets_group_settlements_by_hour_and_list_orders(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);

        $hour = CarbonImmutable::parse('2026-08-10 14:00:00');
        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-REL-1',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '100.000000',
            'paid_at' => $hour->subDays(20),
            'ordered_at' => $hour->subDays(20),
        ]);

        MarketplacePayment::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'external_payment_id' => 'pay-rel-1',
            'currency_code' => 'MXN',
            'transaction_amount' => '100.000000',
            'net_received_amount' => '83.000000',
            'paid_at' => $hour->subDays(20),
            'money_release_at' => $hour->addMinutes(15),
            'is_released' => true,
            'reconciliation_status' => 'balanced',
            'provenance' => 'collections',
        ]);

        CashLedgerEntry::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'entry_type' => 'settlement',
            'transaction_type' => 'SETTLEMENT',
            'external_source_id' => 'pay-rel-1',
            'external_order_id' => 'ORD-REL-1',
            'net_amount' => '83.000000',
            'currency_code' => 'MXN',
            'occurred_at' => $hour->subDays(20),
            'released_at' => $hour->addMinutes(15),
            'is_released' => true,
            'provenance' => 'mp_settlement_report',
            'idempotency_key' => 'bucket|pay-rel-1',
            'payload' => [],
        ]);

        CashLedgerEntry::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'entry_type' => 'settlement',
            'transaction_type' => 'SETTLEMENT',
            'external_source_id' => 'pay-rel-2',
            'net_amount' => '50.000000',
            'currency_code' => 'MXN',
            'occurred_at' => $hour->subDays(19),
            'released_at' => $hour->addMinutes(40),
            'is_released' => true,
            'provenance' => 'mp_settlement_report',
            'idempotency_key' => 'bucket|pay-rel-2',
            'payload' => [],
        ]);

        $page = app(BuildCashReleaseBuckets::class)->execute(
            (int) $workspace->id,
            (int) $connection->id,
            $hour->startOfDay(),
            $hour->endOfDay(),
            40,
            1,
        );

        $this->assertGreaterThanOrEqual(1, $page->total());
        $bucket = collect($page->items())->firstWhere('connection_id', $connection->id);
        $this->assertNotNull($bucket);
        $this->assertSame('133.000000', $bucket['net_total']);
        $this->assertSame(2, $bucket['entries_count']);
        $this->assertSame('settlement_estimate', $bucket['source']);

        $detail = app(BuildCashReleaseBucketDetail::class)->execute(
            (int) $workspace->id,
            (string) $bucket['id'],
        );
        $this->assertSame('release_bucket', $detail['kind']);
        $this->assertSame('settlement_estimate', $detail['source']);
        $this->assertSame('133.000000', $detail['net_total']);
        $this->assertTrue(collect($detail['payments'])->contains(
            fn (array $p) => $p['external_payment_id'] === 'pay-rel-1' && $p['external_order_id'] === 'ORD-REL-1',
        ));

        $this->actingAs($user)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('finance.cash.index', ['view' => 'releases', 'connection_id' => $connection->id]))
            ->assertOk();

        $this->actingAs($user)
            ->withSession(['workspace_id' => $workspace->id])
            ->getJson(route('finance.cash.releases.show', ['id' => $bucket['id']]))
            ->assertOk()
            ->assertJsonPath('kind', 'release_bucket')
            ->assertJsonPath('net_total', '133.000000');
    }

    #[Test]
    public function release_buckets_prefer_official_release_report_over_settlement(): void
    {
        $workspace = Workspace::factory()->create();
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);
        $hour = CarbonImmutable::parse('2026-08-10 17:00:00');

        CashLedgerEntry::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'entry_type' => 'settlement',
            'transaction_type' => 'SETTLEMENT',
            'external_source_id' => 'pay-est',
            'net_amount' => '999.000000',
            'currency_code' => 'MXN',
            'occurred_at' => $hour->subDays(5),
            'released_at' => $hour->addMinutes(10),
            'is_released' => true,
            'provenance' => 'mp_settlement_report',
            'idempotency_key' => 'est|pay-est',
            'payload' => [],
        ]);

        CashLedgerEntry::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'entry_type' => 'release',
            'transaction_type' => 'PAYMENT',
            'external_source_id' => 'pay-off',
            'external_order_id' => 'ORD-OFF-1',
            'net_amount' => '10329.040000',
            'currency_code' => 'MXN',
            'occurred_at' => $hour->addMinutes(5),
            'released_at' => $hour->addMinutes(5),
            'is_released' => true,
            'provenance' => 'mp_release_report',
            'idempotency_key' => 'off|pay-off',
            'payload' => [],
        ]);

        $page = app(BuildCashReleaseBuckets::class)->execute(
            (int) $workspace->id,
            (int) $connection->id,
            $hour->startOfDay(),
            $hour->endOfDay(),
        );
        $bucket = collect($page->items())->first();
        $this->assertNotNull($bucket);
        $this->assertSame('official_release', $bucket['source']);
        $this->assertSame('10329.040000', $bucket['net_total']);
        $this->assertSame(1, $bucket['payments_count']);

        $detail = app(BuildCashReleaseBucketDetail::class)->execute(
            (int) $workspace->id,
            (string) $bucket['id'],
        );
        $this->assertSame('official_release', $detail['source']);
        $this->assertSame('10329.040000', $detail['net_total']);
        $this->assertSame(1, $detail['payments_count']);
        $this->assertTrue(collect($detail['payments'])->contains(
            fn (array $p) => $p['external_order_id'] === 'ORD-OFF-1',
        ));
    }

    #[Test]
    public function release_bucket_groups_shipping_credit_with_sale_payment(): void
    {
        $workspace = Workspace::factory()->create();
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);
        $hour = CarbonImmutable::parse('2026-08-10 18:00:00');
        $mlOrderId = '2000017840106932';
        $shippingId = '47724611357';

        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => $mlOrderId,
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '214.500000',
            'paid_at' => $hour->subDays(20),
            'ordered_at' => $hour->subDays(20),
        ]);

        MarketplacePayment::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'external_payment_id' => '171971460675',
            'currency_code' => 'MXN',
            'transaction_amount' => '214.500000',
            'net_received_amount' => '118.600000',
            'paid_at' => $hour->subDays(20),
            'money_release_at' => $hour->addMinutes(10),
            'is_released' => true,
            'reconciliation_status' => 'balanced',
            'provenance' => 'collections',
        ]);

        CashLedgerEntry::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'entry_type' => 'release',
            'transaction_type' => 'PAYMENT',
            'external_source_id' => '171971460675',
            'external_order_id' => $mlOrderId,
            'net_amount' => '118.600000',
            'currency_code' => 'MXN',
            'occurred_at' => $hour->addMinutes(10),
            'released_at' => $hour->addMinutes(10),
            'is_released' => true,
            'provenance' => 'mp_release_report',
            'idempotency_key' => 'rel|pay|171971460675',
            'payload' => [],
        ]);

        CashLedgerEntry::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'entry_type' => 'release',
            'transaction_type' => 'SHIPPING',
            'external_source_id' => '172880077168',
            'external_order_id' => $shippingId,
            'net_amount' => '3.310000',
            'currency_code' => 'MXN',
            'occurred_at' => $hour->addMinutes(12),
            'released_at' => $hour->addMinutes(12),
            'is_released' => true,
            'provenance' => 'mp_release_report',
            'idempotency_key' => 'rel|ship|172880077168',
            'payload' => [],
        ]);

        CashLedgerEntry::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'entry_type' => 'settlement',
            'transaction_type' => 'SETTLEMENT_SHIPPING',
            'external_source_id' => '172880077168',
            'external_order_id' => $mlOrderId,
            'external_shipping_id' => $shippingId,
            'external_reference' => $shippingId,
            'net_amount' => '3.310000',
            'currency_code' => 'MXN',
            'occurred_at' => $hour->subDays(20),
            'released_at' => $hour->addMinutes(12),
            'is_released' => true,
            'provenance' => 'mp_settlement_report',
            'idempotency_key' => 'set|ship|172880077168',
            'payload' => [],
        ]);

        $page = app(BuildCashReleaseBuckets::class)->execute(
            (int) $workspace->id,
            (int) $connection->id,
            $hour->startOfDay(),
            $hour->endOfDay(),
        );
        $bucket = collect($page->items())->first();
        $this->assertNotNull($bucket);
        $this->assertSame('official_release', $bucket['source']);
        $this->assertSame(2, $bucket['entries_count']);
        $this->assertSame(1, $bucket['payments_count']);
        $this->assertSame('121.910000', $bucket['net_total']);

        $detail = app(BuildCashReleaseBucketDetail::class)->execute(
            (int) $workspace->id,
            (string) $bucket['id'],
        );
        $this->assertSame(1, $detail['payments_count']);
        $this->assertSame('121.910000', $detail['net_total']);
        $this->assertCount(1, $detail['groups']);

        $group = $detail['groups'][0];
        $this->assertSame('order', $group['kind']);
        $this->assertSame($mlOrderId, $group['external_order_id']);
        $this->assertSame($order->id, $group['order_id']);
        $this->assertSame('121.910000', $group['net_total']);
        $this->assertCount(2, $group['lines']);
        $this->assertTrue($group['has_shipping_credit']);
        $this->assertFalse($group['can_fetch_order']);

        $concepts = collect($group['lines'])->pluck('concept')->all();
        $this->assertContains('sale', $concepts);
        $this->assertContains('shipping_credit', $concepts);
        $this->assertSame('Cobro de la venta', collect($group['lines'])->firstWhere('concept', 'sale')['concept_label']);
        $this->assertSame('Envío que pagó el comprador', collect($group['lines'])->firstWhere('concept', 'shipping_credit')['concept_label']);
    }

    #[Test]
    public function release_bucket_groups_dispute_reserve_with_sale_and_does_not_count_as_released(): void
    {
        $workspace = Workspace::factory()->create();
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);
        $hour = CarbonImmutable::parse('2026-08-10 20:00:00');
        $mlOrderId = '2000017825798198';

        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => $mlOrderId,
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '200.000000',
            'paid_at' => $hour->subDays(10),
            'ordered_at' => $hour->subDays(10),
        ]);

        MarketplacePayment::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'external_payment_id' => '172734291880',
            'currency_code' => 'MXN',
            'transaction_amount' => '200.000000',
            'net_received_amount' => '133.170000',
            'status' => 'in_mediation',
            'paid_at' => $hour->subDays(10),
            'money_release_at' => $hour->addMinutes(5),
            'is_released' => true,
            'reconciliation_status' => 'balanced',
            'provenance' => 'collections',
        ]);

        CashLedgerEntry::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'entry_type' => 'release',
            'transaction_type' => 'PAYMENT',
            'external_source_id' => '172734291880',
            'external_order_id' => $mlOrderId,
            'net_amount' => '133.170000',
            'currency_code' => 'MXN',
            'occurred_at' => $hour->addMinutes(5),
            'released_at' => $hour->addMinutes(5),
            'is_released' => true,
            'provenance' => 'mp_release_report',
            'idempotency_key' => 'rel|pay|172734291880',
            'payload' => [],
        ]);

        CashLedgerEntry::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'entry_type' => 'release',
            'transaction_type' => 'RESERVE_FOR_DISPUTE',
            'external_source_id' => '172734291880',
            'external_order_id' => $mlOrderId,
            'net_amount' => '-133.170000',
            'currency_code' => 'MXN',
            'occurred_at' => $hour->addMinutes(5),
            'released_at' => $hour->addMinutes(5),
            'is_released' => true,
            'provenance' => 'mp_release_report',
            'idempotency_key' => 'rel|rsv|172734291880',
            'payload' => [],
        ]);

        $page = app(BuildCashReleaseBuckets::class)->execute(
            (int) $workspace->id,
            (int) $connection->id,
            $hour->startOfDay(),
            $hour->endOfDay(),
        );
        $bucket = collect($page->items())->first();
        $this->assertNotNull($bucket);
        $this->assertSame(2, $bucket['entries_count']);
        $this->assertSame(0, $bucket['payments_count']);
        $this->assertSame('0.000000', $bucket['net_total']);

        $detail = app(BuildCashReleaseBucketDetail::class)->execute(
            (int) $workspace->id,
            (string) $bucket['id'],
        );
        $this->assertSame(0, $detail['payments_count']);
        $this->assertCount(1, $detail['groups']);
        $group = $detail['groups'][0];
        $this->assertSame('order', $group['kind']);
        $this->assertSame($mlOrderId, $group['external_order_id']);
        $this->assertCount(2, $group['lines']);
        $this->assertSame('0.000000', $group['net_total']);
        $this->assertTrue($group['has_dispute']);
        $this->assertFalse($group['can_fetch_order']);
        $this->assertSame('in_mediation', $group['reconciliation_status']);
        $labels = collect($group['lines'])->pluck('concept_label')->all();
        $this->assertContains('Cobro de la venta', $labels);
        $this->assertContains('Reserva por reclamo', $labels);
    }

    #[Test]
    public function shipping_release_without_order_is_not_fetchable(): void
    {
        $workspace = Workspace::factory()->create();
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);
        $hour = CarbonImmutable::parse('2026-08-10 19:00:00');

        CashLedgerEntry::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'entry_type' => 'release',
            'transaction_type' => 'SHIPPING',
            'external_source_id' => '172880099999',
            'external_order_id' => '47724611357',
            'net_amount' => '3.310000',
            'currency_code' => 'MXN',
            'occurred_at' => $hour->addMinutes(5),
            'released_at' => $hour->addMinutes(5),
            'is_released' => true,
            'provenance' => 'mp_release_report',
            'idempotency_key' => 'rel|orphan-ship',
            'payload' => [],
        ]);

        $detail = app(BuildCashReleaseBucketDetail::class)->execute(
            (int) $workspace->id,
            $connection->id.'|'.$hour->format('Y-m-d\TH:00:00'),
        );

        $this->assertSame(0, $detail['payments_count']);
        $this->assertCount(1, $detail['groups']);
        $group = $detail['groups'][0];
        $this->assertSame('shipping', $group['kind']);
        $this->assertFalse($group['can_fetch_order']);
        $this->assertSame('shipping_release', $group['reconciliation_status']);
        $this->assertFalse(collect($detail['payments'])->contains(fn (array $p) => $p['can_fetch_order'] === true));
    }

    #[Test]
    public function withdrawals_tab_lists_bank_payouts(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);

        CashLedgerEntry::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'entry_type' => 'withdrawal',
            'transaction_type' => 'PAYOUTS',
            'external_source_id' => '172487795506',
            'net_amount' => '-132450.000000',
            'currency_code' => 'MXN',
            'occurred_at' => now()->subDay(),
            'provenance' => 'mp_settlement_report',
            'idempotency_key' => 'payout|172487795506',
            'payload' => [],
        ]);

        $this->actingAs($user)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('finance.cash.index', ['view' => 'withdrawals']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/Cash/Index')
                ->where('view', 'withdrawals')
                ->where('withdrawals_total', 1));
    }
}
