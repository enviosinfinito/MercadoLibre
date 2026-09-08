<?php

namespace Tests\Feature\Cash;

use App\Domain\Cash\Actions\AttributeWithdrawalsFifo;
use App\Domain\Cash\Actions\BuildMarketplacePaymentDetail;
use App\Domain\Cash\Actions\BuildOrderCashDiff;
use App\Domain\Cash\Actions\IngestMercadoPagoReportRows;
use App\Domain\Cash\Actions\ReconcileCashLedger;
use App\Domain\Cash\Actions\SyncMarketplacePaymentForOrder;
use App\Domain\Cash\Actions\UpsertMarketplacePayment;
use App\Domain\Finance\Actions\ResolveMercadoLibreSettlement;
use App\Models\CashLedgerEntry;
use App\Models\CashReconciliationLink;
use App\Models\Connection;
use App\Models\MarketplacePayment;
use App\Models\Order;
use App\Models\ProfitSnapshot;
use App\Models\RawResourceSnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CashReconciliationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function upsert_payment_computes_diff_against_expected_net(): void
    {
        [$workspace, $connection, $order] = $this->seedOrderWithExpectedNet('83.000000');

        $payment = app(UpsertMarketplacePayment::class)->execute($order, 'pay-1', [
            'id' => 'pay-1',
            'status' => 'approved',
            'transaction_amount' => '100',
            'marketplace_fee' => '12',
            'shipping_cost' => '0',
            'net_received_amount' => '80',
            'currency_id' => 'MXN',
        ]);

        $this->assertSame('short', $payment->reconciliation_status);
        $this->assertSame('3.000000', (string) $payment->diff_amount);
        $this->assertDatabaseHas('marketplace_payments', [
            'external_payment_id' => 'pay-1',
            'order_id' => $order->id,
        ]);
    }

    #[Test]
    public function ingest_and_reconcile_matches_by_source_id(): void
    {
        [$workspace, $connection, $order] = $this->seedOrderWithExpectedNet('83.000000');

        app(UpsertMarketplacePayment::class)->execute($order, '111', [
            'transaction_amount' => '100',
            'marketplace_fee' => '12',
            'net_received_amount' => '83',
            'currency_id' => 'MXN',
            'status' => 'approved',
        ]);

        app(IngestMercadoPagoReportRows::class)->execute($connection, 'settlement', [[
            'SOURCE_ID' => '111',
            'ORDER_ID' => $order->external_order_id,
            'TRANSACTION_TYPE' => 'SETTLEMENT',
            'TRANSACTION_AMOUNT' => '100.00',
            'MKP_FEE_AMOUNT' => '12.00',
            'TAXES_AMOUNT' => '5.00',
            'SETTLEMENT_NET_AMOUNT' => '83.00',
            'SETTLEMENT_DATE' => '2026-08-01T10:00:00Z',
            'MONEY_RELEASE_DATE' => '2026-08-02T10:00:00Z',
            'IS_RELEASED' => 'TRUE',
        ]]);

        $run = app(ReconcileCashLedger::class)->execute($connection);

        $this->assertSame(1, CashLedgerEntry::query()->count());
        $this->assertDatabaseHas('cash_reconciliation_links', [
            'order_id' => $order->id,
            'match_method' => 'source_id',
            'status' => 'balanced',
        ]);
        $this->assertContains($run->status, ['balanced', 'incomplete']);

        $payment = MarketplacePayment::query()->where('external_payment_id', '111')->first();
        $this->assertNotNull($payment);
        $this->assertSame('balanced', $payment->reconciliation_status);
    }

    #[Test]
    public function build_order_cash_diff_exposes_esperado_real_diff_rows(): void
    {
        [$workspace, $connection, $order] = $this->seedOrderWithExpectedNet('83.000000');
        app(UpsertMarketplacePayment::class)->execute($order, 'pay-2', [
            'transaction_amount' => '100',
            'marketplace_fee' => '12',
            'net_received_amount' => '80',
            'currency_id' => 'MXN',
        ]);

        $diff = app(BuildOrderCashDiff::class)->execute($order);

        $this->assertSame('short', $diff['status']);
        $neto = collect($diff['rows'])->firstWhere('concept', 'Neto cobrado');
        $this->assertNotNull($neto);
        $this->assertSame('83.000000', $neto['expected']);
        $this->assertSame('80.000000', $neto['actual']);
        $this->assertSame('3.000000', $neto['diff']);
    }

    #[Test]
    public function build_order_cash_diff_includes_buyer_shipping_and_wallet_total(): void
    {
        [$workspace, $connection, $order] = $this->seedOrderWithExpectedNet('118.600000');
        $mlOrderId = '2000017840106932';
        $shippingId = '47724611357';
        $order->update(['external_order_id' => $mlOrderId, 'total_amount' => '214.500000']);

        $payment = app(UpsertMarketplacePayment::class)->execute($order->fresh(), '171971460675', [
            'transaction_amount' => '214.50',
            'marketplace_fee' => '50',
            'net_received_amount' => '118.60',
            'currency_id' => 'MXN',
            'status' => 'approved',
        ]);

        CashLedgerEntry::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'entry_type' => 'settlement',
            'transaction_type' => 'SETTLEMENT',
            'external_source_id' => '171971460675',
            'external_order_id' => $mlOrderId,
            'net_amount' => '118.600000',
            'currency_code' => 'MXN',
            'occurred_at' => now()->subDays(2),
            'released_at' => now()->subDay(),
            'is_released' => true,
            'provenance' => 'mp_settlement_report',
            'idempotency_key' => 'set|pay|171971460675',
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
            'occurred_at' => now()->subDays(2),
            'released_at' => now()->subDay(),
            'is_released' => true,
            'provenance' => 'mp_settlement_report',
            'idempotency_key' => 'set|ship|172880077168',
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
            'occurred_at' => now()->subDay(),
            'released_at' => now()->subDay(),
            'is_released' => true,
            'provenance' => 'mp_release_report',
            'idempotency_key' => 'rel|ship|172880077168',
            'payload' => [],
        ]);

        $diff = app(BuildOrderCashDiff::class)->execute($order->fresh());
        $byConcept = collect($diff['rows'])->keyBy('concept');

        $this->assertSame('118.600000', $byConcept['Neto cobrado']['actual']);
        $this->assertSame('3.310000', $byConcept['Envío que pagó el comprador']['actual']);
        $this->assertNull($byConcept['Envío que pagó el comprador']['expected']);
        $this->assertSame('121.910000', $byConcept['Total en saldo MP']['actual']);
        $this->assertSame('121.910000', $diff['wallet_total']);
        $this->assertTrue($diff['has_shipping_credit']);

        $trail = app(BuildMarketplacePaymentDetail::class)->execute($payment->fresh()->load('order'));
        $mpRelease = $trail['cashout_trail']['mp_release'];
        $this->assertNotNull($mpRelease);
        $this->assertSame('121.910000', $mpRelease['total_released']);
        $this->assertGreaterThanOrEqual(2, count($mpRelease['movements'] ?? []));
        $movementConcepts = collect($mpRelease['movements'])->pluck('concept')->all();
        $this->assertContains('sale', $movementConcepts);
        $this->assertContains('shipping_credit', $movementConcepts);
    }

    #[Test]
    public function dispute_reserve_keeps_two_trail_movements_and_zero_wallet(): void
    {
        [$workspace, $connection, $order] = $this->seedOrderWithExpectedNet('133.170000');
        $mlOrderId = '2000017825798198';
        $order->update(['external_order_id' => $mlOrderId, 'total_amount' => '200.000000']);

        $payment = app(UpsertMarketplacePayment::class)->execute($order->fresh(), '172734291880', [
            'transaction_amount' => '200',
            'marketplace_fee' => '50',
            'net_received_amount' => '133.17',
            'currency_id' => 'MXN',
            'status' => 'in_mediation',
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
            'occurred_at' => now()->subDay(),
            'released_at' => now()->subDay(),
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
            'occurred_at' => now()->subDay(),
            'released_at' => now()->subDay(),
            'is_released' => true,
            'provenance' => 'mp_release_report',
            'idempotency_key' => 'rel|rsv|172734291880',
            'payload' => [],
        ]);

        $diff = app(BuildOrderCashDiff::class)->execute($order->fresh());
        $this->assertSame('reserved', $diff['status']);
        $this->assertTrue($diff['reserved']);
        $byConcept = collect($diff['rows'])->keyBy('concept');
        $this->assertSame('133.170000', $byConcept['Neto cobrado']['actual']);
        $this->assertSame('-133.170000', $byConcept['Reserva / reclamo']['actual']);
        $this->assertSame('0.000000', $byConcept['Total en saldo MP']['actual']);
        $this->assertSame('0.000000', $diff['wallet_total']);

        $trail = app(BuildMarketplacePaymentDetail::class)->execute($payment->fresh()->load('order'));
        $mpRelease = $trail['cashout_trail']['mp_release'];
        $this->assertNotNull($mpRelease);
        $this->assertCount(2, $mpRelease['movements']);
        $this->assertSame('0.000000', $mpRelease['total_released']);
        $this->assertTrue($mpRelease['has_dispute']);
        $this->assertSame('reserved', $mpRelease['status']);
        $labels = collect($mpRelease['movements'])->pluck('concept_label')->all();
        $this->assertContains('Cobro de la venta', $labels);
        $this->assertContains('Reserva por reclamo', $labels);
    }

    #[Test]
    public function payments_index_flags_buyer_shipping_from_short_shipping_release(): void
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
        $mlOrderId = '2000017840106932';
        $shippingId = '47724611357';
        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => $mlOrderId,
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '214.500000',
            'paid_at' => now()->subDays(20),
            'ordered_at' => now()->subDays(20),
        ]);
        MarketplacePayment::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'external_payment_id' => '171971460675',
            'currency_code' => 'MXN',
            'transaction_amount' => '214.500000',
            'net_received_amount' => '118.600000',
            'paid_at' => now()->subDays(20),
            'is_released' => true,
            'reconciliation_status' => 'balanced',
            'provenance' => 'collections',
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
            'occurred_at' => now()->subDay(),
            'released_at' => now()->subDay(),
            'is_released' => true,
            'provenance' => 'mp_release_report',
            'idempotency_key' => 'rel|ship|only|172880077168',
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
            'occurred_at' => now()->subDays(20),
            'released_at' => now()->subDay(),
            'is_released' => true,
            'provenance' => 'mp_settlement_report',
            'idempotency_key' => 'set|ship|172880077168',
            'payload' => [],
        ]);

        $this->actingAs($user)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('finance.cash.index', ['view' => 'payments']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/Cash/Index')
                ->where('rows.data.0.has_shipping_credit', true));
    }

    #[Test]
    public function split_collections_sum_nets_and_square_at_order_level(): void
    {
        [$workspace, $connection, $order] = $this->seedOrderWithFeeShippingAndTax(
            revenue: '1883.620000',
            saleFee: '282.540000',
            shipping: '222.500000',
            tax: '170.500000',
            net: '1208.080000',
        );
        $order->update(['external_order_id' => '2000017833192260', 'total_amount' => '1883.620000']);

        $snapshot = RawResourceSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'resource_type' => 'order',
            'external_id' => '2000017833192260',
            'checksum' => 'split-pay',
            'fetched_at' => now(),
            'payload' => [
                'id' => 2000017833192260,
                'total_amount' => 1883.62,
                'payments' => [
                    [
                        'id' => 172812178880,
                        'status' => 'approved',
                        'transaction_amount' => 1476,
                        'marketplace_fee' => 282.54,
                        'shipping_cost' => 0,
                        'net_received_amount' => 837.36,
                    ],
                    [
                        'id' => 172812322630,
                        'status' => 'approved',
                        'transaction_amount' => 407.62,
                        'marketplace_fee' => 0,
                        'shipping_cost' => 0,
                        'net_received_amount' => 370.72,
                    ],
                ],
            ],
        ]);
        $order->update(['raw_snapshot_id' => $snapshot->id]);

        $settlement = app(ResolveMercadoLibreSettlement::class)->execute($order->fresh(), '222.500000', allowFetch: true);
        $this->assertSame('1208.080000', $settlement['net_received']);
        $this->assertSame('282.540000', $settlement['marketplace_fee']);
        $this->assertSame('170.500000', $settlement['tax_total']);

        $payments = app(SyncMarketplacePaymentForOrder::class)->execute($order->fresh(), allowFetch: false);
        $this->assertCount(2, $payments);
        $this->assertSame('0.000000', (string) $payments[0]->shipping_cost_amount);
        $this->assertSame('0.000000', (string) $payments[1]->shipping_cost_amount);
        $this->assertSame('balanced', $payments[0]->fresh()->reconciliation_status);
        $this->assertSame('balanced', $payments[1]->fresh()->reconciliation_status);

        $diff = app(BuildOrderCashDiff::class)->execute($order->fresh());
        $this->assertSame('balanced', $diff['status']);
        $byConcept = collect($diff['rows'])->keyBy('concept');
        $this->assertSame('1883.620000', $byConcept['Ingreso']['actual']);
        $this->assertSame('282.540000', $byConcept['Comisión']['actual']);
        $this->assertSame('222.500000', $byConcept['Envío (seller)']['actual']);
        $this->assertSame('170.500000', $byConcept['Impuestos / retención']['actual']);
        $this->assertSame('1208.080000', $byConcept['Neto cobrado']['expected']);
        $this->assertSame('1208.080000', $byConcept['Neto cobrado']['actual']);
        $this->assertSame('0.000000', $byConcept['Neto cobrado']['diff']);
    }

    #[Test]
    public function shipping_in_expected_fees_is_split_and_not_absorbed_into_tax(): void
    {
        [$workspace, $connection, $order] = $this->seedOrderWithFeeShippingAndTax(
            revenue: '407.380000',
            saleFee: '61.110000',
            shipping: '76.000000',
            tax: '36.880000',
            net: '233.390000',
        );

        $payment = app(UpsertMarketplacePayment::class)->execute($order, '173054509096', [
            'transaction_amount' => '407.38',
            'marketplace_fee' => '61.11',
            'shipping_cost' => '0',
            'net_received_amount' => '233.39',
            'currency_id' => 'MXN',
            'status' => 'approved',
        ]);

        $this->assertSame('76.000000', (string) $payment->shipping_cost_amount);
        $this->assertSame('36.880000', (string) $payment->tax_amount);
        $this->assertSame('balanced', $payment->reconciliation_status);

        $diff = app(BuildOrderCashDiff::class)->execute($order);
        $this->assertSame('balanced', $diff['status']);

        $byConcept = collect($diff['rows'])->keyBy('concept');
        $this->assertSame('61.110000', $byConcept['Comisión']['expected']);
        $this->assertSame('61.110000', $byConcept['Comisión']['actual']);
        $this->assertSame('0.000000', $byConcept['Comisión']['diff']);

        $this->assertSame('76.000000', $byConcept['Envío (seller)']['expected']);
        $this->assertSame('76.000000', $byConcept['Envío (seller)']['actual']);
        $this->assertSame('0.000000', $byConcept['Envío (seller)']['diff']);

        $this->assertSame('36.880000', $byConcept['Impuestos / retención']['expected']);
        $this->assertSame('36.880000', $byConcept['Impuestos / retención']['actual']);
        $this->assertSame('0.000000', $byConcept['Impuestos / retención']['diff']);

        $this->assertSame('233.390000', $byConcept['Neto cobrado']['expected']);
        $this->assertSame('233.390000', $byConcept['Neto cobrado']['actual']);
        $this->assertSame('0.000000', $byConcept['Neto cobrado']['diff']);
    }

    #[Test]
    public function build_diff_realigns_legacy_payment_with_shipping_absorbed_in_tax(): void
    {
        [$workspace, $connection, $order] = $this->seedOrderWithFeeShippingAndTax(
            revenue: '407.380000',
            saleFee: '61.110000',
            shipping: '76.000000',
            tax: '36.880000',
            net: '233.390000',
        );

        MarketplacePayment::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'external_payment_id' => 'legacy-pay',
            'status' => 'approved',
            'transaction_amount' => '407.380000',
            'marketplace_fee_amount' => '61.110000',
            'shipping_cost_amount' => '0.000000',
            'tax_amount' => '112.880000',
            'net_received_amount' => '233.390000',
            'currency_code' => 'MXN',
            'reconciliation_status' => 'balanced',
            'expected_net_amount' => '233.390000',
            'diff_amount' => '0.000000',
            'provenance' => ['source' => 'mercadolibre_collections'],
        ]);

        $diff = app(BuildOrderCashDiff::class)->execute($order);
        $byConcept = collect($diff['rows'])->keyBy('concept');

        $this->assertSame('76.000000', $byConcept['Envío (seller)']['actual']);
        $this->assertSame('36.880000', $byConcept['Impuestos / retención']['actual']);
        $this->assertSame('0.000000', $byConcept['Impuestos / retención']['diff']);
        $this->assertSame('balanced', $diff['status']);
    }

    #[Test]
    public function finance_cash_payment_show_returns_json_for_member(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        [$workspace2, $connection, $order] = $this->seedOrderWithExpectedNet('83.000000');
        $connection->forceFill(['workspace_id' => $workspace->id])->save();
        $order->forceFill(['workspace_id' => $workspace->id, 'connection_id' => $connection->id])->save();
        $payment = app(UpsertMarketplacePayment::class)->execute($order->fresh(), 'show-pay-1', [
            'transaction_amount' => '100',
            'marketplace_fee' => '12',
            'net_received_amount' => '83',
            'currency_id' => 'MXN',
            'money_release_date' => now()->addDays(28)->toIso8601String(),
        ]);

        // Sibling payment released the same day → same "paguito".
        $orderB = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-B-'.uniqid(),
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '50.000000',
            'paid_at' => now(),
            'ordered_at' => now(),
        ]);
        ProfitSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'order_id' => $orderB->id,
            'stage' => 'expected',
            'revenue_amount' => '50.000000',
            'fees_amount' => '5.000000',
            'cogs_amount' => '0.000000',
            'profit_amount' => '40.000000',
            'currency_code' => 'MXN',
            'is_incomplete' => false,
            'payload' => [
                'net_received_amount' => '40.000000',
                'marketplace_net_amount' => '40.000000',
                'breakdown' => ['marketplace_net' => '40.000000'],
            ],
        ]);
        $siblingRelease = $payment->fresh()->money_release_at?->toIso8601String()
            ?? now()->addDays(28)->toIso8601String();
        app(UpsertMarketplacePayment::class)->execute($orderB, 'show-pay-2', [
            'transaction_amount' => '50',
            'marketplace_fee' => '5',
            'net_received_amount' => '40',
            'currency_id' => 'MXN',
            'money_release_date' => $siblingRelease,
        ]);

        CashLedgerEntry::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'entry_type' => 'settlement',
            'transaction_type' => 'SETTLEMENT',
            'external_source_id' => 'show-pay-1',
            'external_order_id' => $order->external_order_id,
            'net_amount' => '83.000000',
            'currency_code' => 'MXN',
            'occurred_at' => now(),
            'released_at' => $payment->fresh()->money_release_at,
            'is_released' => true,
            'provenance' => 'mp_settlement_report',
            'idempotency_key' => 'test|show-pay-1|settlement',
            'payload' => [],
        ]);

        $this->actingAs($user)
            ->withSession(['workspace_id' => $workspace->id])
            ->getJson(route('finance.cash.payments.show', $payment->id))
            ->assertOk()
            ->assertJsonPath('kind', 'payment')
            ->assertJsonPath('payment.id', $payment->id)
            ->assertJsonPath('payment.external_payment_id', 'show-pay-1')
            ->assertJsonPath('release_batch.payments_count', 2)
            ->assertJsonPath('release_batch.total_count', 2)
            ->assertJsonPath('release_batch.truncated', false)
            ->assertJsonPath('release_batch.release_status', 'scheduled')
            ->assertJsonPath('aligned.net', '83.000000')
            ->assertJsonCount(1, 'ledger_entries')
            ->assertJsonPath('cashout_trail.collection.external_payment_id', 'show-pay-1')
            ->assertJsonPath('cashout_trail.mp_release.external_source_id', 'show-pay-1')
            ->assertJsonPath('cashout_trail.mp_release.status', 'balanced')
            ->assertJsonPath('cashout_trail.bank_withdrawal', null)
            ->assertJsonStructure([
                'diff' => ['status', 'rows', 'payments'],
                'release_batch' => ['payments', 'net_total', 'total_count', 'truncated', 'release_status'],
                'cashout_trail' => ['collection', 'mp_release', 'bank_withdrawal'],
            ]);
    }

    #[Test]
    public function withdrawal_fifo_attributes_released_payments_to_payout(): void
    {
        [$workspace, $connection, $orderA] = $this->seedOrderWithExpectedNet('50.000000');
        $orderB = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-FIFO-B-'.uniqid(),
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '40.000000',
            'paid_at' => now()->subDay(),
            'ordered_at' => now()->subDay(),
        ]);
        ProfitSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'order_id' => $orderB->id,
            'stage' => 'expected',
            'revenue_amount' => '40.000000',
            'fees_amount' => '0.000000',
            'cogs_amount' => '0.000000',
            'profit_amount' => '30.000000',
            'currency_code' => 'MXN',
            'is_incomplete' => false,
            'payload' => [
                'net_received_amount' => '30.000000',
                'marketplace_net_amount' => '30.000000',
            ],
        ]);

        $releaseAt = now()->subDays(2);
        $payA = MarketplacePayment::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $orderA->id,
            'external_payment_id' => 'fifo-a',
            'transaction_amount' => '60.000000',
            'marketplace_fee_amount' => '10.000000',
            'net_received_amount' => '50.000000',
            'expected_net_amount' => '50.000000',
            'currency_code' => 'MXN',
            'money_release_at' => $releaseAt,
            'is_released' => true,
            'reconciliation_status' => 'balanced',
            'diff_amount' => '0.000000',
            'provenance' => ['source' => 'test'],
        ]);
        $payB = MarketplacePayment::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $orderB->id,
            'external_payment_id' => 'fifo-b',
            'transaction_amount' => '40.000000',
            'marketplace_fee_amount' => '10.000000',
            'net_received_amount' => '30.000000',
            'expected_net_amount' => '30.000000',
            'currency_code' => 'MXN',
            'money_release_at' => $releaseAt->copy()->addHour(),
            'is_released' => true,
            'reconciliation_status' => 'balanced',
            'diff_amount' => '0.000000',
            'provenance' => ['source' => 'test'],
        ]);

        $withdrawal = CashLedgerEntry::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'entry_type' => 'withdrawal',
            'transaction_type' => 'WITHDRAWAL',
            'external_source_id' => 'payout-999',
            'net_amount' => '80.000000',
            'currency_code' => 'MXN',
            'occurred_at' => now()->subDay(),
            'provenance' => 'mp_release_report',
            'idempotency_key' => 'test|payout-999|withdrawal',
            'payload' => [],
        ]);

        $result = app(AttributeWithdrawalsFifo::class)->execute($connection);

        $this->assertSame(2, $result['links_created']);
        $this->assertDatabaseHas('cash_reconciliation_links', [
            'marketplace_payment_id' => $payA->id,
            'cash_ledger_entry_id' => $withdrawal->id,
            'match_method' => 'withdrawal_fifo',
            'status' => 'balanced',
            'allocated_amount' => '50.000000',
        ]);
        $this->assertDatabaseHas('cash_reconciliation_links', [
            'marketplace_payment_id' => $payB->id,
            'cash_ledger_entry_id' => $withdrawal->id,
            'match_method' => 'withdrawal_fifo',
            'status' => 'balanced',
            'allocated_amount' => '30.000000',
        ]);

        $detailA = app(BuildMarketplacePaymentDetail::class)->execute($payA->fresh());
        $this->assertSame('payout-999', $detailA['cashout_trail']['bank_withdrawal']['external_source_id']);
        $this->assertSame('balanced', $detailA['cashout_trail']['bank_withdrawal']['status']);
        $this->assertSame('50.000000', $detailA['cashout_trail']['bank_withdrawal']['allocated_amount']);
    }

    #[Test]
    public function withdrawal_fifo_leaves_payment_unattributed_when_payout_too_small(): void
    {
        [$workspace, $connection, $orderA] = $this->seedOrderWithExpectedNet('50.000000');
        $orderB = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-FIFO-SMALL-'.uniqid(),
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '40.000000',
            'paid_at' => now()->subDay(),
            'ordered_at' => now()->subDay(),
        ]);

        $releaseAt = now()->subDays(2);
        $payA = MarketplacePayment::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $orderA->id,
            'external_payment_id' => 'fifo-small-a',
            'net_received_amount' => '50.000000',
            'expected_net_amount' => '50.000000',
            'currency_code' => 'MXN',
            'money_release_at' => $releaseAt,
            'is_released' => true,
            'reconciliation_status' => 'balanced',
            'provenance' => ['source' => 'test'],
        ]);
        $payB = MarketplacePayment::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $orderB->id,
            'external_payment_id' => 'fifo-small-b',
            'net_received_amount' => '30.000000',
            'expected_net_amount' => '30.000000',
            'currency_code' => 'MXN',
            'money_release_at' => $releaseAt->copy()->addHour(),
            'is_released' => true,
            'reconciliation_status' => 'balanced',
            'provenance' => ['source' => 'test'],
        ]);

        CashLedgerEntry::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'entry_type' => 'withdrawal',
            'transaction_type' => 'WITHDRAWAL',
            'external_source_id' => 'payout-small',
            'net_amount' => '50.000000',
            'currency_code' => 'MXN',
            'occurred_at' => now()->subDay(),
            'provenance' => 'mp_release_report',
            'idempotency_key' => 'test|payout-small|withdrawal',
            'payload' => [],
        ]);

        $result = app(AttributeWithdrawalsFifo::class)->execute($connection);

        $this->assertSame(1, $result['links_created']);
        $this->assertSame(1, CashReconciliationLink::query()->where('match_method', 'withdrawal_fifo')->count());
        $this->assertDatabaseHas('cash_reconciliation_links', [
            'marketplace_payment_id' => $payA->id,
            'match_method' => 'withdrawal_fifo',
        ]);
        $this->assertDatabaseMissing('cash_reconciliation_links', [
            'marketplace_payment_id' => $payB->id,
            'match_method' => 'withdrawal_fifo',
        ]);

        $detailB = app(BuildMarketplacePaymentDetail::class)->execute($payB->fresh());
        $this->assertNull($detailB['cashout_trail']['bank_withdrawal']);
    }

    #[Test]
    public function release_batch_reports_truncated_when_cohort_exceeds_limit(): void
    {
        [$workspace, $connection, $order] = $this->seedOrderWithExpectedNet('10.000000');
        $releaseAt = now()->addDays(28);

        $current = MarketplacePayment::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'external_payment_id' => 'batch-current',
            'transaction_amount' => '20.000000',
            'marketplace_fee_amount' => '5.000000',
            'shipping_cost_amount' => '0.000000',
            'tax_amount' => '5.000000',
            'net_received_amount' => '10.000000',
            'currency_code' => 'MXN',
            'money_release_at' => $releaseAt,
            'is_released' => false,
            'reconciliation_status' => 'balanced',
            'expected_net_amount' => '10.000000',
            'diff_amount' => '0.000000',
            'provenance' => ['source' => 'test'],
        ]);

        $rows = [];
        for ($i = 1; $i <= 200; $i++) {
            $rows[] = [
                'workspace_id' => $workspace->id,
                'connection_id' => $connection->id,
                'order_id' => null,
                'external_payment_id' => 'batch-sib-'.$i,
                'transaction_amount' => '20.000000',
                'marketplace_fee_amount' => '5.000000',
                'shipping_cost_amount' => '0.000000',
                'tax_amount' => '5.000000',
                'net_received_amount' => '10.000000',
                'currency_code' => 'MXN',
                'money_release_at' => $releaseAt,
                'is_released' => false,
                'reconciliation_status' => 'balanced',
                'expected_net_amount' => '10.000000',
                'diff_amount' => '0.000000',
                'provenance' => json_encode(['source' => 'test']),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        MarketplacePayment::query()->insert($rows);

        $detail = app(BuildMarketplacePaymentDetail::class)->execute($current->fresh());

        $this->assertTrue($detail['release_batch']['truncated']);
        $this->assertSame(201, $detail['release_batch']['total_count']);
        $this->assertSame(200, $detail['release_batch']['payments_count']);
        $this->assertSame('scheduled', $detail['release_batch']['release_status']);
        $this->assertSame('2010.000000', $detail['release_batch']['net_total']);
        $this->assertTrue(collect($detail['release_batch']['payments'])->contains(
            fn (array $row) => ($row['external_payment_id'] ?? null) === 'batch-current' && ($row['is_current'] ?? false),
        ));
    }

    #[Test]
    public function finance_cash_index_requires_auth(): void
    {
        $this->get(route('finance.cash.index'))->assertRedirect();
    }

    #[Test]
    public function finance_cash_index_renders_for_member(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        [$workspace2, $connection, $order] = $this->seedOrderWithExpectedNet('83.000000');
        // Attach seeded order to the acting workspace for list visibility.
        $connection->forceFill(['workspace_id' => $workspace->id])->save();
        $order->forceFill(['workspace_id' => $workspace->id, 'connection_id' => $connection->id])->save();
        app(UpsertMarketplacePayment::class)->execute($order->fresh(), 'list-pay-1', [
            'transaction_amount' => '100',
            'marketplace_fee' => '12',
            'net_received_amount' => '83',
            'currency_id' => 'MXN',
        ]);

        $this->actingAs($user)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('finance.cash.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/Cash/Index')
                ->has('rows.data', 1)
                ->where('payments_total', 1)
                ->where('view', 'payments'));
    }

    /**
     * @return array{0: Workspace, 1: Connection, 2: Order}
     */
    private function seedOrderWithExpectedNet(string $expectedNet): array
    {
        $workspace = Workspace::factory()->create();
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);
        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-'.uniqid(),
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '100.000000',
            'paid_at' => now(),
            'ordered_at' => now(),
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
                'net_received_amount' => $expectedNet,
                'marketplace_net_amount' => $expectedNet,
                'breakdown' => [
                    'fees' => [['event_type' => 'expected_fee', 'amount' => '12.000000']],
                    'taxes_retention' => [['event_type' => 'expected_tax_retention', 'amount' => '5.000000']],
                    'marketplace_net' => $expectedNet,
                ],
            ],
        ]);

        return [$workspace, $connection, $order];
    }

    /**
     * @return array{0: Workspace, 1: Connection, 2: Order}
     */
    private function seedOrderWithFeeShippingAndTax(
        string $revenue,
        string $saleFee,
        string $shipping,
        string $tax,
        string $net,
    ): array {
        $workspace = Workspace::factory()->create();
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);
        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-'.uniqid(),
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => $revenue,
            'paid_at' => now(),
            'ordered_at' => now(),
        ]);

        $feesTotal = bcadd($saleFee, $shipping, 6);

        ProfitSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'order_id' => $order->id,
            'stage' => 'expected',
            'revenue_amount' => $revenue,
            'fees_amount' => $feesTotal,
            'cogs_amount' => '0.000000',
            'profit_amount' => bcsub(bcsub($net, '0', 6), '0', 6),
            'currency_code' => 'MXN',
            'is_incomplete' => false,
            'payload' => [
                'taxes_retention_total' => $tax,
                'net_received_amount' => $net,
                'marketplace_net_amount' => $net,
                'breakdown' => [
                    'fees' => [
                        ['event_type' => 'expected_fee_sale', 'amount' => $saleFee, 'label' => 'Comisión de venta'],
                        ['event_type' => 'expected_shipping_cost', 'amount' => $shipping, 'label' => 'Costo de envío'],
                    ],
                    'fees_total' => $feesTotal,
                    'taxes_retention' => [
                        ['event_type' => 'expected_tax_retention', 'amount' => $tax],
                    ],
                    'taxes_retention_total' => $tax,
                    'marketplace_net' => $net,
                ],
            ],
        ]);

        return [$workspace, $connection, $order];
    }
}
