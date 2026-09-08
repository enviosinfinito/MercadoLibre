<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Integrations\Actions\EnsureFreshConnectionToken;
use App\Domain\Sales\Support\ParseCfdiSummary;
use App\Domain\Sales\Support\SatInvoiceFileCodec;
use App\Integrations\Contracts\ConnectorRegistry;
use App\Integrations\MercadoLibre\Connector\MercadoLibreConnector;
use App\Models\Order;
use Throwable;

final class FetchOrderSatInvoice
{
    public const STATUS_FOUND = 'found';

    public const STATUS_NOT_FOUND = 'not_found';

    public const STATUS_FORBIDDEN = 'forbidden';

    public const STATUS_ERROR = 'error';

    public const SOURCE_FACTURADOR = 'facturador';

    public const SOURCE_FISCAL_DOCUMENTS = 'fiscal_documents';

    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly EnsureFreshConnectionToken $ensureToken,
    ) {}

    /**
     * @return array{
     *   status: string,
     *   source: string|null,
     *   uuid: string|null,
     *   folio: string|null,
     *   serie: string|null,
     *   issuer_rfc: string|null,
     *   receiver_rfc: string|null,
     *   total: string|null,
     *   issued_at: string|null,
     *   pdf_base64: string|null,
     *   xml_base64: string|null,
     *   error: string|null
     * }
     */
    public function execute(Order $order, bool $includeFiles = true): array
    {
        $order->loadMissing(['connection', 'pack']);
        $connection = $order->connection;

        if ($connection === null || $connection->provider !== 'mercadolibre') {
            return $this->result(self::STATUS_ERROR, error: 'Solo se pueden consultar facturas SAT de Mercado Libre.');
        }

        $sellerId = is_string($connection->external_user_id) ? $connection->external_user_id : null;
        $orderId = is_string($order->external_order_id) && $order->external_order_id !== ''
            ? $order->external_order_id
            : null;

        if ($sellerId === null || $sellerId === '' || $orderId === null) {
            return $this->persistProbe($order, $this->result(
                self::STATUS_ERROR,
                error: 'Falta seller_id u order_id para consultar la factura SAT.',
            ));
        }

        try {
            $token = $this->ensureToken->execute($connection);
        } catch (Throwable $e) {
            return $this->persistProbe($order, $this->result(
                self::STATUS_ERROR,
                error: 'No se pudo obtener el access_token: '.$e->getMessage(),
            ));
        }

        /** @var MercadoLibreConnector $connector */
        $connector = $this->registry->get('mercadolibre');

        $facturador = $this->tryFacturador($connector, $sellerId, $orderId, $token, $includeFiles);
        if ($facturador['status'] === self::STATUS_FORBIDDEN || $facturador['status'] === self::STATUS_FOUND) {
            return $this->persistProbe($order, $facturador);
        }

        $packId = $order->messagingPackExternalId();
        if ($packId === null || $packId === '') {
            return $this->persistProbe($order, $this->result(self::STATUS_NOT_FOUND));
        }

        $fiscal = $this->tryFiscalDocuments($connector, $packId, $token, $includeFiles);
        if ($fiscal['status'] === self::STATUS_NOT_FOUND && $facturador['status'] === self::STATUS_ERROR) {
            return $this->persistProbe($order, $facturador);
        }

        return $this->persistProbe($order, $fiscal);
    }

    /**
     * @return array{
     *   status: string,
     *   source: string|null,
     *   uuid: string|null,
     *   folio: string|null,
     *   serie: string|null,
     *   issuer_rfc: string|null,
     *   receiver_rfc: string|null,
     *   total: string|null,
     *   issued_at: string|null,
     *   pdf_base64: string|null,
     *   xml_base64: string|null,
     *   error: string|null
     * }
     */
    private function tryFacturador(
        MercadoLibreConnector $connector,
        string $sellerId,
        string $orderId,
        string $token,
        bool $includeFiles,
    ): array {
        $list = $connector->listSellerInvoices($sellerId, $orderId, $token);
        if ($this->isForbiddenStatus($list)) {
            return $this->forbiddenResult(self::SOURCE_FACTURADOR);
        }
        if ($this->isNotFound($list['status'])) {
            return $this->result(self::STATUS_NOT_FOUND, source: self::SOURCE_FACTURADOR);
        }
        if (! $list['ok']) {
            return $this->result(
                self::STATUS_ERROR,
                source: self::SOURCE_FACTURADOR,
                error: 'No se pudo consultar el Facturador SAT (HTTP '.$list['status'].').',
            );
        }

        $fromList = $this->hydrateFromInvoiceJson($list['json'], $includeFiles);
        if ($fromList !== null) {
            return $fromList;
        }

        $invoiceId = $this->firstInvoiceId($list['json']);
        if ($invoiceId === null) {
            return $this->result(self::STATUS_NOT_FOUND, source: self::SOURCE_FACTURADOR);
        }

        $detail = $connector->fetchSellerInvoice($sellerId, $invoiceId, $token);
        if ($this->isForbiddenStatus($detail)) {
            return $this->forbiddenResult(self::SOURCE_FACTURADOR);
        }
        if ($this->isNotFound($detail['status'])) {
            return $this->result(self::STATUS_NOT_FOUND, source: self::SOURCE_FACTURADOR);
        }
        if (! $detail['ok'] || ! is_array($detail['json'])) {
            return $this->result(
                self::STATUS_ERROR,
                source: self::SOURCE_FACTURADOR,
                error: 'No se pudo obtener la factura del Facturador SAT (HTTP '.$detail['status'].').',
            );
        }

        $fromDetail = $this->hydrateFromInvoiceJson($detail['json'], $includeFiles);
        if ($fromDetail !== null) {
            return $fromDetail;
        }

        return $this->result(self::STATUS_NOT_FOUND, source: self::SOURCE_FACTURADOR);
    }

    /**
     * @return array{
     *   status: string,
     *   source: string|null,
     *   uuid: string|null,
     *   folio: string|null,
     *   serie: string|null,
     *   issuer_rfc: string|null,
     *   receiver_rfc: string|null,
     *   total: string|null,
     *   issued_at: string|null,
     *   pdf_base64: string|null,
     *   xml_base64: string|null,
     *   error: string|null
     * }
     */
    private function tryFiscalDocuments(
        MercadoLibreConnector $connector,
        string $packId,
        string $token,
        bool $includeFiles,
    ): array {
        $list = $connector->listPackFiscalDocuments($packId, $token);
        if ($this->isForbiddenStatus($list)) {
            return $this->forbiddenResult(self::SOURCE_FISCAL_DOCUMENTS);
        }
        if ($this->isNotFound($list['status'])) {
            return $this->result(self::STATUS_NOT_FOUND, source: self::SOURCE_FISCAL_DOCUMENTS);
        }
        if (! $list['ok']) {
            return $this->result(
                self::STATUS_ERROR,
                source: self::SOURCE_FISCAL_DOCUMENTS,
                error: 'No se pudieron listar los documentos fiscales del pack (HTTP '.$list['status'].').',
            );
        }

        $documents = $this->fiscalDocumentRows($list['json']);
        if ($documents === []) {
            return $this->result(self::STATUS_NOT_FOUND, source: self::SOURCE_FISCAL_DOCUMENTS);
        }

        $pdfBase64 = null;
        $xmlBase64 = null;
        $issuedAt = null;

        foreach ($documents as $document) {
            $id = isset($document['id']) ? (string) $document['id'] : '';
            if ($id === '') {
                continue;
            }

            $fileType = strtolower((string) ($document['file_type'] ?? $document['type'] ?? ''));
            $filename = strtolower((string) ($document['filename'] ?? ''));
            $date = isset($document['date']) && is_string($document['date']) ? $document['date'] : null;
            if ($issuedAt === null && $date !== null && $date !== '') {
                $issuedAt = $date;
            }

            $download = $connector->downloadPackFiscalDocument($packId, $id, $token);
            if (! $download['ok'] || $download['body'] === '') {
                continue;
            }

            $encoded = SatInvoiceFileCodec::toBase64($download['body']);
            $isPdf = str_contains($fileType, 'pdf')
                || str_ends_with($filename, '.pdf')
                || SatInvoiceFileCodec::looksLikePdf($download['body']);
            $isXml = str_contains($fileType, 'xml')
                || str_ends_with($filename, '.xml')
                || SatInvoiceFileCodec::looksLikeXml($download['body']);

            if ($isPdf && $pdfBase64 === null) {
                $pdfBase64 = $includeFiles ? $encoded : null;
            }
            if ($isXml && $xmlBase64 === null) {
                $xmlBase64 = $includeFiles ? $encoded : null;
            }
        }

        if ($pdfBase64 === null && $xmlBase64 === null && $includeFiles) {
            return $this->result(
                self::STATUS_ERROR,
                source: self::SOURCE_FISCAL_DOCUMENTS,
                error: 'El pack tiene documentos fiscales pero no se pudieron descargar.',
            );
        }
        if ($pdfBase64 === null && $xmlBase64 === null && ! $includeFiles) {
            return $this->result(self::STATUS_FOUND, source: self::SOURCE_FISCAL_DOCUMENTS, issuedAt: $issuedAt);
        }

        $summary = $this->summaryFromXmlBase64($xmlBase64) ?? [
            'uuid' => null,
            'folio' => null,
            'serie' => null,
            'issuer_rfc' => null,
            'receiver_rfc' => null,
            'total' => null,
            'issued_at' => $issuedAt,
        ];

        return $this->result(
            self::STATUS_FOUND,
            source: self::SOURCE_FISCAL_DOCUMENTS,
            uuid: $summary['uuid'],
            folio: $summary['folio'],
            serie: $summary['serie'],
            issuerRfc: $summary['issuer_rfc'],
            receiverRfc: $summary['receiver_rfc'],
            total: $summary['total'],
            issuedAt: $summary['issued_at'] ?? $issuedAt,
            pdfBase64: $pdfBase64,
            xmlBase64: $xmlBase64,
        );
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array{
     *   status: string,
     *   source: string|null,
     *   uuid: string|null,
     *   folio: string|null,
     *   serie: string|null,
     *   issuer_rfc: string|null,
     *   receiver_rfc: string|null,
     *   total: string|null,
     *   issued_at: string|null,
     *   pdf_base64: string|null,
     *   xml_base64: string|null,
     *   error: string|null
     * }|null
     */
    private function hydrateFromInvoiceJson(?array $json, bool $includeFiles): ?array
    {
        if ($json === null) {
            return null;
        }

        $candidates = [$json];
        if (isset($json['results'][0]) && is_array($json['results'][0])) {
            $candidates[] = $json['results'][0];
        }
        if (isset($json['invoices'][0]) && is_array($json['invoices'][0])) {
            $candidates[] = $json['invoices'][0];
        }
        if (isset($json['invoice']) && is_array($json['invoice'])) {
            $candidates[] = $json['invoice'];
        }

        foreach ($candidates as $candidate) {
            $pdf = $this->extractEncodedFile($candidate, ['pdf', 'pdf_file', 'pdf_base64', 'file_pdf']);
            $xml = $this->extractEncodedFile($candidate, ['xml', 'xml_file', 'xml_base64', 'file_xml']);
            if ($pdf === null && $xml === null) {
                continue;
            }

            $pdfBase64 = $includeFiles ? SatInvoiceFileCodec::toBase64($pdf) : null;
            $xmlBase64 = $includeFiles ? SatInvoiceFileCodec::toBase64($xml) : null;
            $summary = $this->summaryFromXmlBase64($xmlBase64)
                ?? $this->summaryFromInvoicePayload($candidate);

            return $this->result(
                self::STATUS_FOUND,
                source: self::SOURCE_FACTURADOR,
                uuid: $summary['uuid'],
                folio: $summary['folio'],
                serie: $summary['serie'],
                issuerRfc: $summary['issuer_rfc'],
                receiverRfc: $summary['receiver_rfc'],
                total: $summary['total'],
                issuedAt: $summary['issued_at'],
                pdfBase64: $pdfBase64,
                xmlBase64: $xmlBase64,
            );
        }

        return null;
    }

    /**
     * @return array{
     *   status: string,
     *   source: string|null,
     *   uuid: string|null,
     *   folio: string|null,
     *   serie: string|null,
     *   issuer_rfc: string|null,
     *   receiver_rfc: string|null,
     *   total: string|null,
     *   issued_at: string|null,
     *   pdf_base64: string|null,
     *   xml_base64: string|null,
     *   error: string|null
     * }
     */
    private function forbiddenResult(string $source): array
    {
        return $this->result(
            self::STATUS_FORBIDDEN,
            source: $source,
            error: 'La app de Mercado Libre no tiene permiso de Facturación. Actívalo en DevCenter y vuelve a autorizar la cuenta.',
        );
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function firstInvoiceId(?array $json): ?string
    {
        if ($json === null) {
            return null;
        }

        $candidates = [];
        if (isset($json['results']) && is_array($json['results'])) {
            $candidates = $json['results'];
        } elseif (isset($json['invoices']) && is_array($json['invoices'])) {
            $candidates = $json['invoices'];
        } elseif (array_is_list($json)) {
            $candidates = $json;
        } elseif (isset($json['id'])) {
            $candidates = [$json];
        }

        foreach ($candidates as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (isset($row['id']) && $row['id'] !== null && $row['id'] !== '') {
                return (string) $row['id'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function extractEncodedFile(array $payload, array $keys): ?string
    {
        foreach ([$payload, is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [], is_array($payload['invoice'] ?? null) ? $payload['invoice'] : []] as $bag) {
            foreach ($keys as $key) {
                $value = $bag[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        $files = $payload['files'] ?? null;
        if (! is_array($files)) {
            return null;
        }

        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }
            $type = strtolower((string) ($file['type'] ?? $file['file_type'] ?? $file['kind'] ?? ''));
            $name = strtolower((string) ($file['filename'] ?? $file['name'] ?? ''));
            $matches = false;
            foreach ($keys as $key) {
                if (str_contains($type, str_replace(['_file', '_base64', 'file_'], '', $key)) || str_contains($name, $key)) {
                    $matches = true;
                    break;
                }
            }
            if (! $matches && (str_contains($type, 'pdf') || str_ends_with($name, '.pdf')) && in_array('pdf', $keys, true)) {
                $matches = true;
            }
            if (! $matches && (str_contains($type, 'xml') || str_ends_with($name, '.xml')) && in_array('xml', $keys, true)) {
                $matches = true;
            }
            if (! $matches) {
                continue;
            }
            foreach (['content', 'data', 'base64', 'file', 'body'] as $contentKey) {
                $value = $file[$contentKey] ?? null;
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return list<array<string, mixed>>
     */
    private function fiscalDocumentRows(?array $json): array
    {
        if ($json === null) {
            return [];
        }

        $rows = $json['fiscal_documents'] ?? $json['documents'] ?? null;
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{uuid: string|null, folio: string|null, serie: string|null, issuer_rfc: string|null, receiver_rfc: string|null, total: string|null, issued_at: string|null}
     */
    private function summaryFromInvoicePayload(array $payload): array
    {
        $uuid = $payload['uuid'] ?? $payload['invoice_key'] ?? $payload['folio_fiscal'] ?? null;
        $folio = $payload['invoice_number'] ?? $payload['folio'] ?? null;
        $serie = $payload['invoice_series'] ?? $payload['serie'] ?? null;
        $total = $payload['amount'] ?? $payload['total'] ?? $payload['invoice_amount'] ?? null;
        $issuedAt = $payload['issued_date'] ?? $payload['date'] ?? $payload['created'] ?? null;

        return [
            'uuid' => is_scalar($uuid) && (string) $uuid !== '' ? (string) $uuid : null,
            'folio' => is_scalar($folio) && (string) $folio !== '' ? (string) $folio : null,
            'serie' => is_scalar($serie) && (string) $serie !== '' ? (string) $serie : null,
            'issuer_rfc' => null,
            'receiver_rfc' => null,
            'total' => is_scalar($total) && (string) $total !== '' ? (string) $total : null,
            'issued_at' => is_scalar($issuedAt) && (string) $issuedAt !== '' ? (string) $issuedAt : null,
        ];
    }

    /**
     * @return array{uuid: string|null, folio: string|null, serie: string|null, issuer_rfc: string|null, receiver_rfc: string|null, total: string|null, issued_at: string|null}|null
     */
    private function summaryFromXmlBase64(?string $xmlBase64): ?array
    {
        $xml = SatInvoiceFileCodec::decode($xmlBase64);
        if ($xml === null) {
            return null;
        }

        return ParseCfdiSummary::fromXml($xml);
    }

    /**
     * @param  array{
     *   status: string,
     *   source: string|null,
     *   uuid: string|null,
     *   folio: string|null,
     *   serie: string|null,
     *   issuer_rfc: string|null,
     *   receiver_rfc: string|null,
     *   total: string|null,
     *   issued_at: string|null,
     *   pdf_base64: string|null,
     *   xml_base64: string|null,
     *   error: string|null
     * }  $result
     * @return array{
     *   status: string,
     *   source: string|null,
     *   uuid: string|null,
     *   folio: string|null,
     *   serie: string|null,
     *   issuer_rfc: string|null,
     *   receiver_rfc: string|null,
     *   total: string|null,
     *   issued_at: string|null,
     *   pdf_base64: string|null,
     *   xml_base64: string|null,
     *   error: string|null
     * }
     */
    private function persistProbe(Order $order, array $result): array
    {
        $meta = is_array($order->meta) ? $order->meta : [];
        $meta['sat_invoice'] = array_filter([
            'status' => $result['status'],
            'source' => $result['source'],
            'uuid' => $result['uuid'],
            'folio' => $result['folio'],
            'fetched_at' => now()->toIso8601String(),
        ], static fn ($value) => $value !== null && $value !== '');

        $order->forceFill(['meta' => $meta])->save();

        return $result;
    }

    /**
     * @return array{
     *   status: string,
     *   source: string|null,
     *   uuid: string|null,
     *   folio: string|null,
     *   serie: string|null,
     *   issuer_rfc: string|null,
     *   receiver_rfc: string|null,
     *   total: string|null,
     *   issued_at: string|null,
     *   pdf_base64: string|null,
     *   xml_base64: string|null,
     *   error: string|null
     * }
     */
    private function result(
        string $status,
        ?string $source = null,
        ?string $uuid = null,
        ?string $folio = null,
        ?string $serie = null,
        ?string $issuerRfc = null,
        ?string $receiverRfc = null,
        ?string $total = null,
        ?string $issuedAt = null,
        ?string $pdfBase64 = null,
        ?string $xmlBase64 = null,
        ?string $error = null,
    ): array {
        return [
            'status' => $status,
            'source' => $source,
            'uuid' => $uuid,
            'folio' => $folio,
            'serie' => $serie,
            'issuer_rfc' => $issuerRfc,
            'receiver_rfc' => $receiverRfc,
            'total' => $total,
            'issued_at' => $issuedAt,
            'pdf_base64' => $pdfBase64,
            'xml_base64' => $xmlBase64,
            'error' => $error,
        ];
    }

    /**
     * @param  array{status: int, json: array<string, mixed>|null, body: string}  $response
     */
    private function isForbiddenStatus(array $response): bool
    {
        if ($this->isForbidden((int) $response['status'])) {
            return true;
        }

        $code = $response['json']['code'] ?? $response['json']['error'] ?? null;

        return is_string($code) && str_contains(strtoupper($code), 'UNAUTHORIZED');
    }

    private function isForbidden(int $status): bool
    {
        return $status === 401 || $status === 403;
    }

    private function isNotFound(int $status): bool
    {
        return $status === 404;
    }
}
