<?php

namespace Tests\Unit\Cash;

use App\Domain\Cash\Support\ParseMercadoPagoReportCsv;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ParseMercadoPagoReportCsvTest extends TestCase
{
    #[Test]
    public function parses_semicolon_settlement_csv(): void
    {
        $csv = <<<CSV
SOURCE_ID;ORDER_ID;TRANSACTION_TYPE;TRANSACTION_AMOUNT;MKP_FEE_AMOUNT;TAXES_AMOUNT;SETTLEMENT_NET_AMOUNT;MONEY_RELEASE_DATE;IS_RELEASED
111;ORD-1;SETTLEMENT;100.00;12.00;5.00;83.00;2026-08-01T10:00:00Z;TRUE
CSV;

        $rows = (new ParseMercadoPagoReportCsv)->execute($csv);

        $this->assertCount(1, $rows);
        $this->assertSame('111', $rows[0]['SOURCE_ID']);
        $this->assertSame('ORD-1', $rows[0]['ORDER_ID']);
        $this->assertSame('83.00', $rows[0]['SETTLEMENT_NET_AMOUNT']);
        $this->assertSame('TRUE', $rows[0]['IS_RELEASED']);
    }
}
