<?php

namespace App\Domain\Sales\Support;

final class ParseCfdiSummary
{
    /**
     * @return array{
     *   uuid: string|null,
     *   folio: string|null,
     *   serie: string|null,
     *   issuer_rfc: string|null,
     *   receiver_rfc: string|null,
     *   total: string|null,
     *   issued_at: string|null
     * }
     */
    public static function fromXml(string $xml): array
    {
        $empty = [
            'uuid' => null,
            'folio' => null,
            'serie' => null,
            'issuer_rfc' => null,
            'receiver_rfc' => null,
            'total' => null,
            'issued_at' => null,
        ];

        $raw = trim($xml);
        if ($raw === '') {
            return $empty;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($raw);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($document === false) {
            return array_merge($empty, [
                'uuid' => self::uuidFromText($raw),
            ]);
        }

        $document->registerXPathNamespace('cfdi4', 'http://www.sat.gob.mx/cfd/4');
        $document->registerXPathNamespace('cfdi3', 'http://www.sat.gob.mx/cfd/3');
        $document->registerXPathNamespace('tfd', 'http://www.sat.gob.mx/TimbreFiscalDigital');

        $attrs = $document->attributes();
        $folio = self::attr($attrs, 'Folio');
        $serie = self::attr($attrs, 'Serie');
        $total = self::attr($attrs, 'Total');
        $issuedAt = self::attr($attrs, 'Fecha');

        $issuerRfc = self::firstXPathAttr($document, '//cfdi4:Emisor/@Rfc | //cfdi3:Emisor/@Rfc | //Emisor/@Rfc');
        $receiverRfc = self::firstXPathAttr($document, '//cfdi4:Receptor/@Rfc | //cfdi3:Receptor/@Rfc | //Receptor/@Rfc');
        $uuid = self::firstXPathAttr(
            $document,
            '//tfd:TimbreFiscalDigital/@UUID | //TimbreFiscalDigital/@UUID',
        ) ?? self::uuidFromText($raw);

        return [
            'uuid' => $uuid,
            'folio' => $folio,
            'serie' => $serie,
            'issuer_rfc' => $issuerRfc,
            'receiver_rfc' => $receiverRfc,
            'total' => $total,
            'issued_at' => $issuedAt,
        ];
    }

    private static function attr(?\SimpleXMLElement $attrs, string $name): ?string
    {
        if ($attrs === null || ! isset($attrs[$name])) {
            return null;
        }

        $value = trim((string) $attrs[$name]);

        return $value !== '' ? $value : null;
    }

    private static function firstXPathAttr(\SimpleXMLElement $document, string $query): ?string
    {
        $nodes = $document->xpath($query);
        if (! is_array($nodes) || $nodes === []) {
            return null;
        }

        $value = trim((string) $nodes[0]);

        return $value !== '' ? $value : null;
    }

    private static function uuidFromText(string $raw): ?string
    {
        if (preg_match('/[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}/', $raw, $match)) {
            return strtoupper($match[0]);
        }

        return null;
    }
}
