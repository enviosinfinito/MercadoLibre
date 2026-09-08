<?php

namespace Tests\Unit;

use App\Domain\Sales\Support\ParseCfdiSummary;
use App\Domain\Sales\Support\SatInvoiceFileCodec;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ParseCfdiSummaryTest extends TestCase
{
    #[Test]
    public function extracts_uuid_folio_and_rfcs_from_cfdi_4(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Serie="B" Folio="88" Fecha="2026-01-02T09:00:00" Total="10.50">
  <cfdi:Emisor Rfc="EMISOR123456"/>
  <cfdi:Receptor Rfc="RECEP1234567"/>
  <cfdi:Complemento>
    <tfd:TimbreFiscalDigital UUID="aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee"/>
  </cfdi:Complemento>
</cfdi:Comprobante>
XML;

        $summary = ParseCfdiSummary::fromXml($xml);

        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $summary['uuid']);
        $this->assertSame('88', $summary['folio']);
        $this->assertSame('B', $summary['serie']);
        $this->assertSame('EMISOR123456', $summary['issuer_rfc']);
        $this->assertSame('RECEP1234567', $summary['receiver_rfc']);
        $this->assertSame('10.50', $summary['total']);
        $this->assertSame('2026-01-02T09:00:00', $summary['issued_at']);
    }

    #[Test]
    public function codec_normalizes_raw_xml_and_base64(): void
    {
        $xml = '<cfdi:Comprobante Folio="1"/>';
        $fromRaw = SatInvoiceFileCodec::toBase64($xml);
        $fromEncoded = SatInvoiceFileCodec::toBase64(base64_encode($xml));

        $this->assertSame(base64_encode($xml), $fromRaw);
        $this->assertSame(base64_encode($xml), $fromEncoded);
        $this->assertSame($xml, SatInvoiceFileCodec::decode($fromEncoded));
        $this->assertTrue(SatInvoiceFileCodec::looksLikeXml($xml));
        $this->assertTrue(SatInvoiceFileCodec::looksLikePdf("%PDF-1.4\n"));
    }
}
