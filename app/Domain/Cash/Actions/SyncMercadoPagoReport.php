<?php

namespace App\Domain\Cash\Actions;

use App\Domain\Cash\Support\CashReportSyncProgress;
use App\Domain\Cash\Support\MercadoPagoReportClient;
use App\Domain\Cash\Support\ParseMercadoPagoReportCsv;
use App\Domain\Integrations\Actions\EnsureFreshConnectionToken;
use App\Domain\Shared\Support\BusinessDay;
use App\Models\Connection;
use App\Models\ConnectionCapability;
use Carbon\CarbonImmutable;
use RuntimeException;

final class SyncMercadoPagoReport
{
    /** @var list<string> */
    private const READY_STATUSES = ['processed', 'available', 'ready', 'enabled'];

    /** @var list<string> */
    private const PENDING_STATUSES = ['pending', 'processing', 'in_process'];

    public function __construct(
        private readonly MercadoPagoReportClient $client,
        private readonly ParseMercadoPagoReportCsv $parser,
        private readonly IngestMercadoPagoReportRows $ingest,
        private readonly EnsureFreshConnectionToken $ensureToken,
        private readonly ReconcileCashLedger $reconcile,
        private readonly StoreCashReportFile $storeReportFile,
    ) {}

    /**
     * @return array{
     *     created: int,
     *     updated: int,
     *     rows: int,
     *     file_name: string|null,
     *     skipped: bool,
     *     reason: string|null,
     *     status: string,
     *     report_id: string|null,
     *     begin: string|null,
     *     end: string|null,
     *     report_shape: string|null
     * }
     */
    public function execute(
        Connection $connection,
        string $reportKind,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        bool $reconcileAfter = true,
        bool $waitInline = false,
    ): array {
        $request = $this->requestReport($connection, $reportKind, $from, $to);

        if (($request['status'] ?? '') === 'skipped') {
            return $this->emptyResult(skipped: true, reason: $request['reason'] ?? 'skipped', status: 'skipped');
        }

        $fileName = $request['file_name'] ?? null;
        if ($fileName === null && $waitInline) {
            $fileName = $this->waitInlineForFileName(
                $reportKind,
                (string) $request['token'],
                (string) $request['begin'],
                (string) $request['end'],
                isset($request['report_id']) ? (string) $request['report_id'] : null,
            );
        }

        if ($fileName === null) {
            return [
                'created' => 0,
                'updated' => 0,
                'rows' => 0,
                'file_name' => null,
                'skipped' => true,
                'reason' => 'report_pending',
                'status' => 'pending',
                'report_id' => isset($request['report_id']) ? (string) $request['report_id'] : null,
                'begin' => $request['begin'] ?? null,
                'end' => $request['end'] ?? null,
                'report_shape' => null,
            ];
        }

        $completed = $this->completeReport(
            $connection,
            $reportKind,
            (string) $request['token'],
            $fileName,
            $reconcileAfter,
            isset($request['from']) && $request['from'] instanceof CarbonImmutable ? $request['from'] : null,
            isset($request['to']) && $request['to'] instanceof CarbonImmutable ? $request['to'] : null,
        );

        return array_merge($completed, [
            'status' => 'ready',
            'report_id' => isset($request['report_id']) ? (string) $request['report_id'] : null,
            'begin' => $request['begin'] ?? null,
            'end' => $request['end'] ?? null,
            'skipped' => false,
            'reason' => null,
        ]);
    }

    /**
     * Phase A: ensure config, reuse or create report. Never blocks on MP generation.
     *
     * @return array{
     *     status: 'ready'|'pending'|'skipped',
     *     file_name: string|null,
     *     report_id: string|null,
     *     begin: string|null,
     *     end: string|null,
     *     token: string|null,
     *     reason: string|null,
     *     from: CarbonImmutable|null,
     *     to: CarbonImmutable|null
     * }
     */
    public function requestReport(
        Connection $connection,
        string $reportKind,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
    ): array {
        $capabilityKey = $this->capabilityKey($reportKind);
        $capability = ConnectionCapability::query()
            ->where('connection_id', $connection->id)
            ->where('capability_key', $capabilityKey)
            ->first();

        if ($capability !== null && ! $capability->enabled) {
            $httpStatus = (int) ($capability->meta['http_status'] ?? 0);
            if ($httpStatus !== 404) {
                CashReportSyncProgress::setReport(
                    (int) $connection->workspace_id,
                    (int) $connection->id,
                    $reportKind,
                    'skipped',
                    'Capability deshabilitada para este reporte.',
                    ['error' => 'capability_disabled'],
                );

                return [
                    'status' => 'skipped',
                    'file_name' => null,
                    'report_id' => null,
                    'begin' => null,
                    'end' => null,
                    'token' => null,
                    'reason' => 'capability_disabled:'.$capabilityKey,
                    'from' => null,
                    'to' => null,
                ];
            }
        }

        $token = $this->ensureToken->execute($connection->loadMissing('credential'));
        CashReportSyncProgress::setReport(
            (int) $connection->workspace_id,
            (int) $connection->id,
            $reportKind,
            'configuring',
            'Revisando configuración del reporte en Mercado Pago…',
        );
        $configResponse = $this->client->ensureConfig($reportKind, $token);
        if ($configResponse->successful()) {
            $this->persistCapability($connection, $capabilityKey, true, $configResponse->status(), 'config OK (present, created, or updated)');
        } elseif (in_array($configResponse->status(), [401, 403], true)) {
            $this->persistCapability($connection, $capabilityKey, false, $configResponse->status(), 'ensureConfig forbidden');
            CashReportSyncProgress::setReport(
                (int) $connection->workspace_id,
                (int) $connection->id,
                $reportKind,
                'skipped',
                'Sin permiso para el reporte (HTTP '.$configResponse->status().').',
                ['error' => 'http_'.$configResponse->status()],
            );

            return [
                'status' => 'skipped',
                'file_name' => null,
                'report_id' => null,
                'begin' => null,
                'end' => null,
                'token' => null,
                'reason' => 'http_'.$configResponse->status(),
                'from' => null,
                'to' => null,
            ];
        }

        [$from, $to, $begin, $end] = $this->resolveWindow($reportKind, $from, $to);
        CashReportSyncProgress::setReport(
            (int) $connection->workspace_id,
            (int) $connection->id,
            $reportKind,
            'requesting',
            'Buscando reporte listo o solicitando uno nuevo…',
            ['begin' => $begin, 'end' => $end],
        );

        $ready = $this->findReadyReport($reportKind, $token, $begin, $end, null);
        if ($ready !== null && ($ready['file_name'] ?? null) !== null) {
            CashReportSyncProgress::setReport(
                (int) $connection->workspace_id,
                (int) $connection->id,
                $reportKind,
                'ready',
                'Archivo listo: '.$ready['file_name'],
                ['file_name' => $ready['file_name'], 'report_id' => $ready['report_id'] ?? null],
            );

            return [
                'status' => 'ready',
                'file_name' => $ready['file_name'],
                'report_id' => $ready['report_id'] ?? null,
                'begin' => $begin,
                'end' => $end,
                'token' => $token,
                'reason' => null,
                'from' => $from,
                'to' => $to,
            ];
        }

        CashReportSyncProgress::setReport(
            (int) $connection->workspace_id,
            (int) $connection->id,
            $reportKind,
            'requesting',
            'Creando reporte en Mercado Pago…',
            ['begin' => $begin, 'end' => $end],
        );
        $create = $this->client->createReport($reportKind, $token, $begin, $end);
        if ($create->status() === 400) {
            $maxEnd = CarbonImmutable::now(BusinessDay::timezone())->endOfDay();
            $end = $maxEnd->utc()->format('Y-m-d\TH:i:s\Z');
            $to = $maxEnd;
            $create = $this->client->createReport($reportKind, $token, $begin, $end);
        }

        if (in_array($create->status(), [401, 403], true)) {
            $this->persistCapability($connection, $capabilityKey, false, $create->status(), 'create report forbidden');
            CashReportSyncProgress::setReport(
                (int) $connection->workspace_id,
                (int) $connection->id,
                $reportKind,
                'skipped',
                'Sin permiso para crear el reporte (HTTP '.$create->status().').',
                ['error' => 'http_'.$create->status()],
            );

            return [
                'status' => 'skipped',
                'file_name' => null,
                'report_id' => null,
                'begin' => $begin,
                'end' => $end,
                'token' => null,
                'reason' => 'http_'.$create->status(),
                'from' => $from,
                'to' => $to,
            ];
        }

        $createJson = $create->json();
        $createJson = is_array($createJson) ? $createJson : null;
        $reportId = $this->extractReportId($createJson);
        $directFile = $this->extractFileName($createJson);
        if ($directFile !== null) {
            CashReportSyncProgress::setReport(
                (int) $connection->workspace_id,
                (int) $connection->id,
                $reportKind,
                'ready',
                'Archivo listo: '.$directFile,
                ['file_name' => $directFile, 'report_id' => $reportId],
            );

            return [
                'status' => 'ready',
                'file_name' => $directFile,
                'report_id' => $reportId,
                'begin' => $begin,
                'end' => $end,
                'token' => $token,
                'reason' => null,
                'from' => $from,
                'to' => $to,
            ];
        }

        // Immediate re-check (sometimes list has file_name before search).
        $ready = $this->findReadyReport($reportKind, $token, $begin, $end, $reportId);
        if ($ready !== null && ($ready['file_name'] ?? null) !== null) {
            CashReportSyncProgress::setReport(
                (int) $connection->workspace_id,
                (int) $connection->id,
                $reportKind,
                'ready',
                'Archivo listo: '.$ready['file_name'],
                ['file_name' => $ready['file_name'], 'report_id' => $ready['report_id'] ?? $reportId],
            );

            return [
                'status' => 'ready',
                'file_name' => $ready['file_name'],
                'report_id' => $ready['report_id'] ?? $reportId,
                'begin' => $begin,
                'end' => $end,
                'token' => $token,
                'reason' => null,
                'from' => $from,
                'to' => $to,
            ];
        }

        CashReportSyncProgress::setReport(
            (int) $connection->workspace_id,
            (int) $connection->id,
            $reportKind,
            'waiting_report',
            'Mercado Pago está generando el reporte (puede tardar varios minutos)…',
            [
                'report_id' => $reportId,
                'attempt' => 0,
                'max_attempts' => $this->pollMaxAttempts(),
                'begin' => $begin,
                'end' => $end,
            ],
        );

        return [
            'status' => 'pending',
            'file_name' => null,
            'report_id' => $reportId,
            'begin' => $begin,
            'end' => $end,
            'token' => $token,
            'reason' => 'report_pending',
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * @return array{file_name: string, report_id: string|null}|null
     */
    public function findReadyReport(
        string $reportKind,
        string $token,
        string $begin,
        string $end,
        ?string $reportId = null,
    ): ?array {
        $candidates = [];

        $search = $this->client->searchReports($reportKind, $token, $begin, $end);
        if ($search->successful()) {
            $candidates = array_merge($candidates, $this->normalizeResultList($search->json()));
        }

        $list = $this->client->listReports($reportKind, $token, 30, 0);
        if ($list->successful()) {
            $candidates = array_merge($candidates, $this->normalizeResultList($list->json()));
        }

        $best = null;
        foreach ($candidates as $item) {
            if (! $this->isReadyItem($item)) {
                continue;
            }
            $name = $this->extractFileName($item);
            if ($name === null) {
                continue;
            }
            $id = $this->extractReportId($item);
            if ($reportId !== null && $id !== null && $id !== $reportId) {
                // Prefer exact id match but still accept covering files for the window.
            }
            $score = 0;
            if ($reportId !== null && $id === $reportId) {
                $score += 100;
            }
            if ($this->windowCovers($item, $begin, $end)) {
                $score += 10;
            }
            if ($best === null || $score > $best['score']) {
                $best = ['file_name' => $name, 'report_id' => $id, 'score' => $score];
            }
        }

        if ($best === null) {
            return null;
        }

        return ['file_name' => $best['file_name'], 'report_id' => $best['report_id']];
    }

    /**
     * @return array{created: int, updated: int, rows: int, file_name: string, report_shape: string, local_file_id: int|null}
     */
    public function completeReport(
        Connection $connection,
        string $reportKind,
        string $token,
        string $fileName,
        bool $reconcileAfter = true,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        ?string $reportId = null,
    ): array {
        CashReportSyncProgress::setReport(
            (int) $connection->workspace_id,
            (int) $connection->id,
            $reportKind,
            'downloading',
            'Descargando '.$fileName.'…',
            ['file_name' => $fileName],
        );

        $download = $this->client->downloadReport($reportKind, $token, $fileName);
        if (! $download->successful()) {
            CashReportSyncProgress::setReport(
                (int) $connection->workspace_id,
                (int) $connection->id,
                $reportKind,
                'failed',
                'Falló la descarga (HTTP '.$download->status().').',
                ['file_name' => $fileName, 'error' => 'download_http_'.$download->status()],
            );
            throw new RuntimeException('Failed to download Mercado Pago report '.$fileName.' (HTTP '.$download->status().')');
        }

        $csv = (string) $download->body();
        unset($download);
        $shape = $this->detectReportShape($csv);
        CashReportSyncProgress::setReport(
            (int) $connection->workspace_id,
            (int) $connection->id,
            $reportKind,
            'parsing',
            'Parseando CSV ('.($shape === 'full' ? 'completo' : 'reserve').')…',
            ['file_name' => $fileName, 'report_shape' => $shape],
        );
        $rows = $this->parser->execute($csv);

        if ($shape === 'reserve') {
            foreach ($rows as $i => $row) {
                $rows[$i]['_report_shape'] = 'reserve';
            }
        }

        $rowCount = count($rows);
        $stored = $this->storeReportFile->execute(
            $connection,
            $reportKind,
            $fileName,
            $csv,
            $shape,
            $rowCount,
            $from,
            $to,
            $reportId,
        );
        unset($csv);

        CashReportSyncProgress::setReport(
            (int) $connection->workspace_id,
            (int) $connection->id,
            $reportKind,
            'ingesting',
            'Ingestando '.$rowCount.' filas al ledger…',
            [
                'file_name' => $fileName,
                'report_shape' => $shape,
                'rows' => $rowCount,
                'local_file_id' => $stored->id,
            ],
        );

        $stats = $this->ingest->execute($connection, $reportKind, $rows);
        unset($rows);

        if ($reconcileAfter) {
            $this->reconcile->execute($connection, $from, $to);
        }

        $shapeLabel = $shape === 'full' ? 'oficial' : 'reserve (enriquecido)';
        CashReportSyncProgress::setReport(
            (int) $connection->workspace_id,
            (int) $connection->id,
            $reportKind,
            'done',
            "Listo ({$shapeLabel}): {$stats['created']} nuevas, {$stats['updated']} actualizadas de {$rowCount} filas.",
            [
                'file_name' => $fileName,
                'report_shape' => $shape,
                'rows' => $rowCount,
                'created' => $stats['created'],
                'updated' => $stats['updated'],
                'local_file_id' => $stored->id,
            ],
        );

        return [
            'created' => $stats['created'],
            'updated' => $stats['updated'],
            'rows' => $rowCount,
            'file_name' => $fileName,
            'report_shape' => $shape,
            'local_file_id' => $stored->id,
        ];
    }

    public function pollDelaySeconds(int $attempt): int
    {
        $delays = (array) config('finance.mercadopago.poll_delays_seconds', [30, 60, 120, 180, 300]);
        $delays = array_values(array_filter(array_map('intval', $delays), fn (int $d) => $d > 0));
        if ($delays === []) {
            $delays = [30, 60, 120];
        }
        $index = max(0, min(count($delays) - 1, $attempt - 1));

        return $delays[$index];
    }

    public function pollMaxAttempts(): int
    {
        return max(1, (int) config('finance.mercadopago.poll_max_attempts', 24));
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string, 3: string}
     */
    private function resolveWindow(string $reportKind, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $tz = BusinessDay::timezone();
        $isRelease = in_array($reportKind, ['release', 'released_money'], true);
        $defaultDays = $isRelease
            ? (int) config('finance.mercadopago.release_default_days', 2)
            : (int) config('finance.mercadopago.settlement_default_days', 14);

        $to ??= CarbonImmutable::now($tz)->endOfDay();
        $from ??= $to->subDays(max(1, $defaultDays))->startOfDay();

        if ($isRelease) {
            $maxEnd = CarbonImmutable::now($tz)->endOfDay();
            if ($to->greaterThan($maxEnd)) {
                $to = $maxEnd;
            }
        }

        $begin = $from->utc()->format('Y-m-d\TH:i:s\Z');
        $end = $to->utc()->format('Y-m-d\TH:i:s\Z');

        return [$from, $to, $begin, $end];
    }

    private function waitInlineForFileName(
        string $reportKind,
        string $token,
        string $begin,
        string $end,
        ?string $reportId,
    ): ?string {
        $attempts = (int) config('finance.mercadopago.poll_attempts', 12);
        $sleepMs = (int) config('finance.mercadopago.poll_sleep_ms', 2500);

        for ($i = 0; $i < $attempts; $i++) {
            usleep(max(100, $sleepMs) * 1000);
            $ready = $this->findReadyReport($reportKind, $token, $begin, $end, $reportId);
            if ($ready !== null) {
                return $ready['file_name'];
            }
        }

        return null;
    }

    /**
     * @param  mixed  $json
     * @return list<array<string, mixed>>
     */
    private function normalizeResultList(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }
        $results = $json['results'] ?? $json;
        if (! is_array($results)) {
            return [];
        }

        $out = [];
        foreach ($results as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isReadyItem(array $item): bool
    {
        $status = strtolower((string) ($item['status'] ?? ''));
        if ($status !== '' && in_array($status, self::PENDING_STATUSES, true)) {
            return false;
        }
        if ($status !== '' && ! in_array($status, self::READY_STATUSES, true)) {
            // Unknown status: still OK if file_name present (list uses "enabled").
            return $this->extractFileName($item) !== null;
        }

        return $this->extractFileName($item) !== null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function windowCovers(array $item, string $begin, string $end): bool
    {
        $itemBegin = (string) ($item['begin_date'] ?? '');
        $itemEnd = (string) ($item['end_date'] ?? '');
        if ($itemBegin === '' || $itemEnd === '') {
            return false;
        }

        return $itemBegin <= $begin && $itemEnd >= $end;
    }

    private function detectReportShape(string $csv): string
    {
        $firstLine = strtok($csv, "\r\n") ?: '';
        $header = strtoupper($firstLine);
        if (str_contains($header, 'NET_CREDIT_AMOUNT') || str_contains($header, 'RECORD_TYPE')) {
            return 'full';
        }

        return 'reserve';
    }

    private function capabilityKey(string $reportKind): string
    {
        return $reportKind === 'release' || $reportKind === 'released_money'
            ? (string) config('finance.cash.capability_keys.release_report')
            : (string) config('finance.cash.capability_keys.settlement_report');
    }

    private function persistCapability(
        Connection $connection,
        string $capabilityKey,
        bool $enabled,
        int $httpStatus,
        string $note,
    ): void {
        ConnectionCapability::query()->updateOrCreate(
            ['connection_id' => $connection->id, 'capability_key' => $capabilityKey],
            [
                'workspace_id' => $connection->workspace_id,
                'enabled' => $enabled,
                'meta' => [
                    'http_status' => $httpStatus,
                    'note' => $note,
                    'probed_at' => now()->toIso8601String(),
                ],
            ],
        );
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function extractFileName(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        foreach (['file_name', 'filename', 'name'] as $key) {
            if (! empty($payload[$key]) && is_string($payload[$key])) {
                return $payload[$key];
            }
        }

        if (isset($payload['files']) && is_array($payload['files'])) {
            foreach ($payload['files'] as $file) {
                if (is_array($file) && ! empty($file['name']) && is_string($file['name'])) {
                    return $file['name'];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function extractReportId(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }
        foreach (['id', 'report_id'] as $key) {
            if (isset($payload[$key]) && (is_string($payload[$key]) || is_numeric($payload[$key]))) {
                return (string) $payload[$key];
            }
        }

        return null;
    }

    /**
     * @return array{
     *     created: int,
     *     updated: int,
     *     rows: int,
     *     file_name: string|null,
     *     skipped: bool,
     *     reason: string|null,
     *     status: string,
     *     report_id: string|null,
     *     begin: string|null,
     *     end: string|null,
     *     report_shape: string|null
     * }
     */
    private function emptyResult(bool $skipped, ?string $reason, string $status): array
    {
        return [
            'created' => 0,
            'updated' => 0,
            'rows' => 0,
            'file_name' => null,
            'skipped' => $skipped,
            'reason' => $reason,
            'status' => $status,
            'report_id' => null,
            'begin' => null,
            'end' => null,
            'report_shape' => null,
        ];
    }
}
