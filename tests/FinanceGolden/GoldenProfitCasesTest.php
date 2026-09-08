<?php

namespace Tests\FinanceGolden;

use App\Domain\Finance\Actions\CalculateExpectedProfit;
use App\Domain\Finance\Actions\RecordExpectedFinancialEvents;
use App\Domain\Inventory\Actions\ReceiveInventory;
use App\Domain\Sales\Actions\UpsertCanonicalOrder;
use App\Domain\Shared\Support\TenantContext;
use App\Domain\Shared\ValueObjects\Money;
use App\Models\Connection;
use App\Models\FinancialEvent;
use App\Models\Product;
use App\Models\Variant;
use App\Models\Warehouse;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GoldenProfitCasesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        return [
            'simple_single_line' => [[
                'sku' => 'G-01',
                'qty' => '1',
                'unit_price' => '100.00',
                'receipts' => [['quantity' => '100', 'unit_cost' => '40.00']],
                'expected_revenue' => '100.000000',
                'expected_fees' => '12.000000',
                'expected_cogs' => '40.000000',
                // 100 - 12 fees - taxes on base/1.16 (2.5%+8%) - 40 cogs
                'expected_profit' => '38.948277',
            ]],
            'multi_qty' => [[
                'sku' => 'G-02',
                'qty' => '3',
                'unit_price' => '50.00',
                'receipts' => [['quantity' => '100', 'unit_cost' => '10.00']],
                'expected_revenue' => '150.000000',
                'expected_fees' => '18.000000',
                'expected_cogs' => '30.000000',
                'expected_profit' => '88.422415',
            ]],
            'zero_cost' => [[
                'sku' => 'G-03',
                'qty' => '2',
                'unit_price' => '25.00',
                'receipts' => [['quantity' => '100', 'unit_cost' => '0.00']],
                'expected_revenue' => '50.000000',
                'expected_fees' => '6.000000',
                'expected_cogs' => '0.000000',
                'expected_profit' => '39.474139',
            ]],
            'high_cost_negative_margin' => [[
                'sku' => 'G-04',
                'qty' => '1',
                'unit_price' => '20.00',
                'receipts' => [['quantity' => '100', 'unit_cost' => '30.00']],
                'expected_revenue' => '20.000000',
                'expected_fees' => '2.400000',
                'expected_cogs' => '30.000000',
                'expected_profit' => '-14.210344',
            ]],
            'fx_reporting_cost' => [[
                'sku' => 'G-05',
                'qty' => '1',
                'unit_price' => '100.00',
                'receipts' => [[
                    'quantity' => '100',
                    'unit_cost' => '5.00',
                    'unit_cost_currency' => 'USD',
                    'fx_rate' => '20',
                    'reporting_currency' => 'MXN',
                ]],
                'expected_revenue' => '100.000000',
                'expected_fees' => '12.000000',
                'expected_cogs' => '100.000000',
                'expected_profit' => '-21.051723',
            ]],
            'fees_only_baseline' => [[
                'sku' => 'G-06',
                'qty' => '1',
                'unit_price' => '200.00',
                'receipts' => [['quantity' => '10', 'unit_cost' => '50.00']],
                'expected_revenue' => '200.000000',
                'expected_fees' => '24.000000',
                'expected_cogs' => '50.000000',
                'expected_profit' => '107.896553',
            ]],
            'partial_refund' => [[
                'sku' => 'G-07',
                'qty' => '1',
                'unit_price' => '100.00',
                'receipts' => [['quantity' => '10', 'unit_cost' => '40.00']],
                'extra_events' => [
                    ['event_type' => 'expected_refund', 'amount' => '-25.00'],
                ],
                'expected_revenue' => '75.000000',
                'expected_fees' => '12.000000',
                'expected_cogs' => '40.000000',
                'expected_profit' => '13.948277',
            ]],
            'full_refund' => [[
                'sku' => 'G-08',
                'qty' => '1',
                'unit_price' => '80.00',
                'receipts' => [['quantity' => '10', 'unit_cost' => '20.00']],
                'extra_events' => [
                    ['event_type' => 'expected_refund', 'amount' => '-80.00'],
                ],
                'expected_revenue' => '0.000000',
                'expected_fees' => '9.600000',
                'expected_cogs' => '20.000000',
                'expected_profit' => '-36.841378',
            ]],
            'missing_cogs_incomplete' => [[
                'sku' => 'G-09',
                'qty' => '5',
                'unit_price' => '10.00',
                'receipts' => [['quantity' => '2', 'unit_cost' => '4.00']],
                'expected_revenue' => '50.000000',
                'expected_fees' => '6.000000',
                'expected_cogs' => '8.000000',
                'expected_profit' => '31.474139',
                'expected_incomplete' => true,
            ]],
            'no_receipt_incomplete' => [[
                'sku' => 'G-10',
                'qty' => '1',
                'unit_price' => '50.00',
                'receipts' => [],
                'expected_revenue' => '50.000000',
                'expected_fees' => '6.000000',
                'expected_cogs' => '0.000000',
                'expected_profit' => '39.474139',
                'expected_incomplete' => true,
            ]],
            'fifo_two_layers' => [[
                'sku' => 'G-11',
                'qty' => '7',
                'unit_price' => '30.00',
                'receipts' => [
                    ['quantity' => '5', 'unit_cost' => '10.00', 'received_at' => '2026-01-01T00:00:00Z'],
                    ['quantity' => '5', 'unit_cost' => '20.00', 'received_at' => '2026-01-02T00:00:00Z'],
                ],
                'expected_revenue' => '210.000000',
                'expected_fees' => '25.200000',
                'expected_cogs' => '90.000000',
                'expected_profit' => '75.791380',
            ]],
            'second_receipt_different_cost' => [[
                'sku' => 'G-12',
                'qty' => '3',
                'unit_price' => '100.00',
                'receipts' => [
                    ['quantity' => '1', 'unit_cost' => '40.00', 'received_at' => '2026-02-01T00:00:00Z'],
                    ['quantity' => '10', 'unit_cost' => '60.00', 'received_at' => '2026-02-10T00:00:00Z'],
                ],
                'expected_revenue' => '300.000000',
                'expected_fees' => '36.000000',
                'expected_cogs' => '160.000000',
                'expected_profit' => '76.844828',
            ]],
            'fx_frozen_at_receipt' => [[
                'sku' => 'G-13',
                'qty' => '2',
                'unit_price' => '250.00',
                'receipts' => [[
                    'quantity' => '5',
                    'unit_cost' => '10.00',
                    'unit_cost_currency' => 'USD',
                    'fx_rate' => '17.50',
                    'reporting_currency' => 'MXN',
                    'received_at' => '2026-03-01T12:00:00Z',
                ]],
                'expected_revenue' => '500.000000',
                'expected_fees' => '60.000000',
                'expected_cogs' => '350.000000',
                'expected_profit' => '44.741380',
            ]],
            'multi_currency_fifo_cogs' => [[
                'sku' => 'G-14',
                'qty' => '4',
                'unit_price' => '100.00',
                'receipts' => [
                    [
                        'quantity' => '2',
                        'unit_cost' => '5.00',
                        'unit_cost_currency' => 'USD',
                        'fx_rate' => '20',
                        'reporting_currency' => 'MXN',
                        'received_at' => '2026-04-01T00:00:00Z',
                    ],
                    [
                        'quantity' => '5',
                        'unit_cost' => '80.00',
                        'unit_cost_currency' => 'MXN',
                        'fx_rate' => '1',
                        'reporting_currency' => 'MXN',
                        'received_at' => '2026-04-02T00:00:00Z',
                    ],
                ],
                // 2*(5*20) + 2*80 = 200 + 160 = 360
                'expected_revenue' => '400.000000',
                'expected_fees' => '48.000000',
                'expected_cogs' => '360.000000',
                'expected_profit' => '-44.206895',
            ]],
            'zero_qty_edge' => [[
                'sku' => 'G-15',
                'qty' => '0',
                'unit_price' => '100.00',
                'receipts' => [['quantity' => '10', 'unit_cost' => '40.00']],
                'expected_revenue' => '0.000000',
                'expected_fees' => '0.000000',
                'expected_cogs' => '0.000000',
                'expected_profit' => '0.000000',
            ]],
            'discount_event' => [[
                'sku' => 'G-16',
                'qty' => '1',
                'unit_price' => '100.00',
                'receipts' => [['quantity' => '10', 'unit_cost' => '30.00']],
                'extra_events' => [
                    ['event_type' => 'expected_discount', 'amount' => '-10.00'],
                ],
                'expected_revenue' => '90.000000',
                'expected_fees' => '12.000000',
                'expected_cogs' => '30.000000',
                'expected_profit' => '38.948277',
            ]],
            'shipping_cost' => [[
                'sku' => 'G-17',
                'qty' => '1',
                'unit_price' => '100.00',
                'receipts' => [['quantity' => '10', 'unit_cost' => '40.00']],
                'extra_events' => [
                    ['event_type' => 'expected_shipping_cost', 'amount' => '-15.50'],
                ],
                'expected_revenue' => '100.000000',
                'expected_fees' => '27.500000',
                'expected_cogs' => '40.000000',
                'expected_profit' => '23.448277',
            ]],
            'advertising_event' => [[
                'sku' => 'G-18',
                'qty' => '1',
                'unit_price' => '100.00',
                'receipts' => [['quantity' => '10', 'unit_cost' => '40.00']],
                'extra_events' => [
                    ['event_type' => 'expected_advertising', 'amount' => '-8.25'],
                ],
                'expected_revenue' => '100.000000',
                'expected_fees' => '20.250000',
                'expected_cogs' => '40.000000',
                'expected_profit' => '30.698277',
            ]],
            'discount_plus_shipping' => [[
                'sku' => 'G-19',
                'qty' => '2',
                'unit_price' => '75.00',
                'receipts' => [['quantity' => '20', 'unit_cost' => '25.00']],
                'extra_events' => [
                    ['event_type' => 'expected_discount', 'amount' => '-5.00'],
                    ['event_type' => 'expected_shipping_cost', 'amount' => '-12.00'],
                ],
                'expected_revenue' => '145.000000',
                'expected_fees' => '30.000000',
                'expected_cogs' => '50.000000',
                'expected_profit' => '51.422415',
            ]],
            'centavo_precision' => [[
                'sku' => 'G-20',
                'qty' => '1',
                'unit_price' => '19.99',
                'receipts' => [['quantity' => '5', 'unit_cost' => '7.33']],
                'expected_revenue' => '19.990000',
                'expected_fees' => '2.398800',
                'expected_cogs' => '7.330000',
                'expected_profit' => '8.451762',
            ]],
            'large_qty_fifo_split' => [[
                'sku' => 'G-21',
                'qty' => '10',
                'unit_price' => '15.00',
                'receipts' => [
                    ['quantity' => '3', 'unit_cost' => '5.00', 'received_at' => '2026-05-01T00:00:00Z'],
                    ['quantity' => '3', 'unit_cost' => '6.00', 'received_at' => '2026-05-02T00:00:00Z'],
                    ['quantity' => '10', 'unit_cost' => '7.00', 'received_at' => '2026-05-03T00:00:00Z'],
                ],
                // 3*5 + 3*6 + 4*7 = 15+18+28 = 61
                'expected_revenue' => '150.000000',
                'expected_fees' => '18.000000',
                'expected_cogs' => '61.000000',
                'expected_profit' => '57.422415',
            ]],
            'refund_and_ads' => [[
                'sku' => 'G-22',
                'qty' => '1',
                'unit_price' => '120.00',
                'receipts' => [['quantity' => '5', 'unit_cost' => '45.00']],
                'extra_events' => [
                    ['event_type' => 'expected_refund', 'amount' => '-20.00'],
                    ['event_type' => 'expected_advertising', 'amount' => '-10.00'],
                ],
                'expected_revenue' => '100.000000',
                'expected_fees' => '24.400000',
                'expected_cogs' => '45.000000',
                'expected_profit' => '19.737932',
            ]],
            'usd_layer_fx_18' => [[
                'sku' => 'G-23',
                'qty' => '1',
                'unit_price' => '500.00',
                'receipts' => [[
                    'quantity' => '3',
                    'unit_cost' => '12.50',
                    'unit_cost_currency' => 'USD',
                    'fx_rate' => '18',
                    'reporting_currency' => 'MXN',
                ]],
                'expected_revenue' => '500.000000',
                'expected_fees' => '60.000000',
                'expected_cogs' => '225.000000',
                'expected_profit' => '169.741380',
            ]],
            'partial_stock_incomplete_fifo' => [[
                'sku' => 'G-24',
                'qty' => '8',
                'unit_price' => '40.00',
                'receipts' => [
                    ['quantity' => '3', 'unit_cost' => '10.00', 'received_at' => '2026-06-01T00:00:00Z'],
                    ['quantity' => '2', 'unit_cost' => '12.00', 'received_at' => '2026-06-02T00:00:00Z'],
                ],
                // 3*10 + 2*12 = 54, unfulfilled 3
                'expected_revenue' => '320.000000',
                'expected_fees' => '38.400000',
                'expected_cogs' => '54.000000',
                'expected_profit' => '198.634484',
                'expected_incomplete' => true,
            ]],
            'bundle_like_high_price' => [[
                'sku' => 'G-25',
                'qty' => '1',
                'unit_price' => '999.99',
                'receipts' => [['quantity' => '2', 'unit_cost' => '450.50']],
                'expected_revenue' => '999.990000',
                'expected_fees' => '119.998800',
                'expected_cogs' => '450.500000',
                'expected_profit' => '338.974865',
            ]],
            'discount_refund_shipping_combo' => [[
                'sku' => 'G-26',
                'qty' => '1',
                'unit_price' => '200.00',
                'receipts' => [['quantity' => '5', 'unit_cost' => '70.00']],
                'extra_events' => [
                    ['event_type' => 'expected_discount', 'amount' => '-15.00'],
                    ['event_type' => 'expected_refund', 'amount' => '-10.00'],
                    ['event_type' => 'expected_shipping_cost', 'amount' => '-22.00'],
                    ['event_type' => 'expected_advertising', 'amount' => '-5.50'],
                ],
                // revenue 175; fees 51.50; taxes 18.50; cogs 70 → 35
                'expected_revenue' => '175.000000',
                'expected_fees' => '51.500000',
                'expected_cogs' => '70.000000',
                'expected_profit' => '35.396553',
            ]],
            'money_vo_centavo_assert' => [[
                'sku' => 'G-27',
                'qty' => '1',
                'unit_price' => '33.33',
                'receipts' => [['quantity' => '1', 'unit_cost' => '11.11']],
                'expected_revenue' => '33.330000',
                'expected_fees' => '3.999600',
                'expected_cogs' => '11.110000',
                'expected_profit' => '15.203462',
                'assert_money_vo' => true,
            ]],
        ];
    }

    #[Test]
    #[DataProvider('cases')]
    public function golden_profit_case(array $case): void
    {
        $workspace = Workspace::factory()->create(['reporting_currency' => 'MXN']);
        TenantContext::set($workspace->id);

        Warehouse::factory()->create([
            'workspace_id' => $workspace->id,
            'is_default' => true,
            'code' => 'MAIN',
        ]);

        $connection = Connection::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => 'u1',
            'status' => 'active',
        ]);

        $product = Product::factory()->create(['workspace_id' => $workspace->id]);
        $variant = Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'sku' => $case['sku'],
        ]);

        foreach ($case['receipts'] as $receipt) {
            app(ReceiveInventory::class)->execute($workspace->id, [
                'variant_id' => $variant->id,
                'quantity' => $receipt['quantity'],
                'unit_cost_amount' => $receipt['unit_cost'],
                'unit_cost_currency' => $receipt['unit_cost_currency'] ?? 'MXN',
                'fx_rate' => $receipt['fx_rate'] ?? '1',
                'reporting_currency' => $receipt['reporting_currency'] ?? 'MXN',
                'received_at' => $receipt['received_at'] ?? now()->toIso8601String(),
            ]);
        }

        $lineTotal = bcmul($case['qty'], $case['unit_price'], 6);

        $order = app(UpsertCanonicalOrder::class)->execute($workspace->id, $connection->id, [
            'external_order_id' => 'GOLD-'.$case['sku'],
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => $lineTotal,
            'ordered_at' => now()->toIso8601String(),
            'lines' => [[
                'sku' => $case['sku'],
                'external_item_id' => 'item-'.$case['sku'],
                'quantity' => $case['qty'],
                'unit_price_amount' => $case['unit_price'],
                'currency_code' => 'MXN',
                'line_total_amount' => $lineTotal,
            ]],
        ]);

        app(RecordExpectedFinancialEvents::class)->execute($order);

        foreach ($case['extra_events'] ?? [] as $event) {
            FinancialEvent::query()->create([
                'workspace_id' => $workspace->id,
                'connection_id' => $connection->id,
                'order_id' => $order->id,
                'order_line_id' => $order->lines()->first()->id,
                'event_type' => $event['event_type'],
                'stage' => 'expected',
                'amount' => $event['amount'],
                'currency_code' => 'MXN',
                'reporting_amount' => $event['amount'],
                'reporting_currency' => 'MXN',
                'occurred_at' => now(),
                'provenance' => ['source' => 'golden'],
            ]);
        }

        $profit = app(CalculateExpectedProfit::class)->execute($order);

        $this->assertSame($case['expected_revenue'], (string) $profit->revenue_amount);
        $this->assertSame($case['expected_fees'], (string) $profit->fees_amount);
        $this->assertSame($case['expected_cogs'], (string) $profit->cogs_amount);
        $this->assertSame($case['expected_profit'], (string) $profit->profit_amount);

        if (array_key_exists('expected_incomplete', $case)) {
            $this->assertSame($case['expected_incomplete'], (bool) $profit->is_incomplete);
        }

        if (! empty($case['assert_money_vo'])) {
            $rev = new Money((string) $profit->revenue_amount, 'MXN');
            $fee = new Money((string) $profit->fees_amount, 'MXN');
            $taxes = new Money((string) ($profit->payload['taxes_retention_total'] ?? '0'), 'MXN');
            $cogs = new Money((string) $profit->cogs_amount, 'MXN');
            $expected = $rev->subtract($fee)->subtract($taxes)->subtract($cogs);
            $this->assertTrue(
                $expected->equals(new Money((string) $profit->profit_amount, 'MXN')),
                'Money VO profit mismatch',
            );
        }

        TenantContext::clear();
    }

    #[Test]
    public function records_isr_iva_retention_and_typed_fee_breakdown(): void
    {
        $workspace = Workspace::factory()->create(['reporting_currency' => 'MXN']);
        TenantContext::set($workspace->id);

        Warehouse::factory()->create([
            'workspace_id' => $workspace->id,
            'is_default' => true,
            'code' => 'MAIN',
        ]);

        $connection = Connection::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => 'u-tax',
            'status' => 'active',
        ]);

        $product = Product::factory()->create(['workspace_id' => $workspace->id]);
        $variant = Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'sku' => 'TAX-01',
        ]);

        app(ReceiveInventory::class)->execute($workspace->id, [
            'variant_id' => $variant->id,
            'quantity' => '10',
            'unit_cost_amount' => '40.00',
            'unit_cost_currency' => 'MXN',
            'fx_rate' => '1',
            'reporting_currency' => 'MXN',
            'received_at' => now()->toIso8601String(),
        ]);

        $order = app(UpsertCanonicalOrder::class)->execute($workspace->id, $connection->id, [
            'external_order_id' => 'GOLD-TAX-01',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '100.00',
            'ordered_at' => now()->toIso8601String(),
            'lines' => [[
                'sku' => 'TAX-01',
                'external_item_id' => 'item-tax-01',
                'quantity' => '1',
                'unit_price_amount' => '100.00',
                'currency_code' => 'MXN',
                'line_total_amount' => '100.00',
            ]],
        ]);

        app(RecordExpectedFinancialEvents::class)->execute($order);
        $profit = app(CalculateExpectedProfit::class)->execute($order);

        $types = FinancialEvent::query()
            ->where('order_id', $order->id)
            ->where('stage', 'expected')
            ->pluck('event_type')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            ['expected_fee_sale', 'expected_revenue', 'expected_tax_isr', 'expected_tax_iva'],
            $types,
        );

        $this->assertSame('100.000000', (string) $profit->revenue_amount);
        $this->assertSame('12.000000', (string) $profit->fees_amount);
        $this->assertSame('9.051723', (string) ($profit->payload['taxes_retention_total'] ?? null));
        $this->assertSame('40.000000', (string) $profit->cogs_amount);
        $this->assertSame('38.948277', (string) $profit->profit_amount);

        $breakdown = $profit->payload['breakdown'] ?? [];
        $this->assertSame('12.000000', $breakdown['fees_total'] ?? null);
        $this->assertSame('9.051723', $breakdown['taxes_retention_total'] ?? null);
        $this->assertCount(1, $breakdown['fees'] ?? []);
        $this->assertSame('expected_fee_sale', $breakdown['fees'][0]['event_type'] ?? null);
        $this->assertSame('Comisión de venta', $breakdown['fees'][0]['label'] ?? null);

        $taxTypes = array_column($breakdown['taxes_retention'] ?? [], 'event_type');
        sort($taxTypes);
        $this->assertSame(['expected_tax_isr', 'expected_tax_iva'], $taxTypes);

        TenantContext::clear();
    }

    #[Test]
    public function legacy_expected_fee_still_counts_in_fees_bucket(): void
    {
        $workspace = Workspace::factory()->create(['reporting_currency' => 'MXN']);
        TenantContext::set($workspace->id);

        Warehouse::factory()->create([
            'workspace_id' => $workspace->id,
            'is_default' => true,
            'code' => 'MAIN',
        ]);

        $connection = Connection::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => 'u-legacy',
            'status' => 'active',
        ]);

        $product = Product::factory()->create(['workspace_id' => $workspace->id]);
        $variant = Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'sku' => 'LEG-01',
        ]);

        app(ReceiveInventory::class)->execute($workspace->id, [
            'variant_id' => $variant->id,
            'quantity' => '10',
            'unit_cost_amount' => '40.00',
            'unit_cost_currency' => 'MXN',
            'fx_rate' => '1',
            'reporting_currency' => 'MXN',
            'received_at' => now()->toIso8601String(),
        ]);

        $order = app(UpsertCanonicalOrder::class)->execute($workspace->id, $connection->id, [
            'external_order_id' => 'GOLD-LEG-01',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '100.00',
            'ordered_at' => now()->toIso8601String(),
            'lines' => [[
                'sku' => 'LEG-01',
                'external_item_id' => 'item-leg-01',
                'quantity' => '1',
                'unit_price_amount' => '100.00',
                'currency_code' => 'MXN',
                'line_total_amount' => '100.00',
            ]],
        ]);

        $lineId = $order->lines()->first()->id;

        foreach ([
            ['event_type' => 'expected_revenue', 'amount' => '100.00'],
            ['event_type' => 'expected_fee', 'amount' => '-12.00'],
            ['event_type' => 'expected_tax_isr', 'amount' => '-2.155172'],
            ['event_type' => 'expected_tax_iva', 'amount' => '-6.896551'],
        ] as $event) {
            FinancialEvent::query()->create([
                'workspace_id' => $workspace->id,
                'connection_id' => $connection->id,
                'order_id' => $order->id,
                'order_line_id' => $lineId,
                'event_type' => $event['event_type'],
                'stage' => 'expected',
                'amount' => $event['amount'],
                'currency_code' => 'MXN',
                'reporting_amount' => $event['amount'],
                'reporting_currency' => 'MXN',
                'occurred_at' => now(),
                'provenance' => ['source' => 'legacy'],
            ]);
        }

        $profit = app(CalculateExpectedProfit::class)->execute($order);

        $this->assertSame('12.000000', (string) $profit->fees_amount);
        $this->assertSame('9.051723', (string) ($profit->payload['taxes_retention_total'] ?? null));
        $this->assertSame('38.948277', (string) $profit->profit_amount);
        $this->assertSame(
            'expected_fee',
            $profit->payload['breakdown']['fees'][0]['event_type'] ?? null,
        );
        $this->assertSame(
            'Comisión (genérica)',
            $profit->payload['breakdown']['fees'][0]['label'] ?? null,
        );

        TenantContext::clear();
    }
}
