<?php

namespace Tests\Unit\Cash;

use App\Domain\Cash\Actions\IngestMercadoPagoReportRows;
use App\Models\CashLedgerEntry;
use App\Models\Connection;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IngestMercadoPagoReleaseRowsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function ingests_full_release_payment_row_with_credit_minus_debit(): void
    {
        $connection = $this->connection();

        $stats = app(IngestMercadoPagoReportRows::class)->execute($connection, 'release', [[
            'DATE' => '2026-08-10T17:00:09.000-06:00',
            'SOURCE_ID' => 'pay-1',
            'EXTERNAL_REFERENCE' => 'ORD-1',
            'RECORD_TYPE' => 'release',
            'DESCRIPTION' => 'payment',
            'NET_CREDIT_AMOUNT' => '100.50',
            'NET_DEBIT_AMOUNT' => '0.00',
            'GROSS_AMOUNT' => '150.00',
            'MP_FEE_AMOUNT' => '-30.00',
            'TAXES_AMOUNT' => '-19.50',
            'PAYMENT_METHOD' => 'visa',
            'BALANCE_AMOUNT' => '1000.00',
        ]]);

        $this->assertSame(1, $stats['created']);
        $this->assertDatabaseHas('cash_ledger_entries', [
            'connection_id' => $connection->id,
            'provenance' => 'mp_release_report',
            'entry_type' => 'release',
            'transaction_type' => 'PAYMENT',
            'external_source_id' => 'pay-1',
            'net_amount' => '100.500000',
            'gross_amount' => '150.000000',
            'fee_amount' => '-30.000000',
            'tax_amount' => '-19.500000',
            'is_released' => 1,
        ]);

        $entry = CashLedgerEntry::query()->where('external_source_id', 'pay-1')->first();
        $this->assertNotNull($entry?->released_at);
        $this->assertNotNull($entry?->occurred_at);
        $this->assertSame('payment', $entry->payload['DESCRIPTION'] ?? null);
        $this->assertArrayNotHasKey('EXTRA_NOISE', $entry->payload);
    }

    #[Test]
    public function skips_summary_record_types(): void
    {
        $connection = $this->connection();

        $stats = app(IngestMercadoPagoReportRows::class)->execute($connection, 'release', [
            [
                'DATE' => '2026-08-10T00:00:00.000-06:00',
                'RECORD_TYPE' => 'initial_available_balance',
                'NET_CREDIT_AMOUNT' => '999.00',
                'NET_DEBIT_AMOUNT' => '0.00',
            ],
            [
                'DATE' => '2026-08-10T23:59:59.000-06:00',
                'RECORD_TYPE' => 'subtotal',
                'NET_CREDIT_AMOUNT' => '10.00',
                'NET_DEBIT_AMOUNT' => '0.00',
            ],
            [
                'DATE' => '2026-08-10T17:00:00.000-06:00',
                'SOURCE_ID' => 'pay-keep',
                'RECORD_TYPE' => 'release',
                'DESCRIPTION' => 'payment',
                'NET_CREDIT_AMOUNT' => '12.00',
                'NET_DEBIT_AMOUNT' => '0.00',
            ],
        ]);

        $this->assertSame(1, $stats['created']);
        $this->assertDatabaseHas('cash_ledger_entries', [
            'external_source_id' => 'pay-keep',
            'net_amount' => '12.000000',
        ]);
        $this->assertSame(1, CashLedgerEntry::query()->where('connection_id', $connection->id)->count());
    }

    #[Test]
    public function reserve_rows_enrich_net_from_settlement_source(): void
    {
        $connection = $this->connection();

        CashLedgerEntry::query()->create([
            'workspace_id' => $connection->workspace_id,
            'connection_id' => $connection->id,
            'entry_type' => 'settlement',
            'transaction_type' => 'SETTLEMENT',
            'external_source_id' => 'src-reserve',
            'net_amount' => '55.250000',
            'currency_code' => 'MXN',
            'occurred_at' => now()->subDay(),
            'released_at' => now(),
            'provenance' => 'mp_settlement_report',
            'idempotency_key' => 'settlement|src-reserve',
            'payload' => [],
        ]);

        $stats = app(IngestMercadoPagoReportRows::class)->execute($connection, 'release', [[
            'DATE' => '2026-08-10T17:00:00.000-06:00',
            'SOURCE_ID' => 'src-reserve',
            'EXTERNAL_REFERENCE' => 'ORD-R',
            '_report_shape' => 'reserve',
        ]]);

        $this->assertSame(1, $stats['created']);
        $entry = CashLedgerEntry::query()
            ->where('provenance', 'mp_release_report')
            ->where('external_source_id', 'src-reserve')
            ->first();
        $this->assertNotNull($entry);
        $this->assertSame('55.250000', (string) $entry->net_amount);
        $this->assertSame('reserve', $entry->payload['_report_shape'] ?? null);
    }

    private function connection(): Connection
    {
        $workspace = Workspace::factory()->create();

        return Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);
    }
}
