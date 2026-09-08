<?php

namespace App\Domain\Cash\Support;

use App\Integrations\Support\LoggedHttpClient;
use Illuminate\Http\Client\Response;
use RuntimeException;

final class MercadoPagoReportClient
{
    public function __construct(
        private readonly LoggedHttpClient $http,
    ) {}

    public function baseUrl(): string
    {
        return rtrim((string) config('finance.mercadopago.api_base_url', config('connectors.mercadopago.api_base_url')), '/');
    }

    /**
     * @param  list<string>|null  $columns
     */
    public function ensureConfig(string $reportKind, string $token, ?array $columns = null): Response
    {
        $path = $this->configPath($reportKind);
        $columns ??= $this->desiredColumns($reportKind);
        $get = $this->http->get($this->baseUrl().$path, [], $token, 30);

        if (in_array($get->status(), [401, 403], true)) {
            return $get;
        }

        if ($get->successful()) {
            if ($this->configMissingColumns($get->json(), $columns)) {
                return $this->updateConfig($reportKind, $token, $columns);
            }

            return $get;
        }

        // 404 / other = config missing → create.
        return $this->createConfig($reportKind, $token, $columns);
    }

    /**
     * @param  list<string>|null  $columns
     */
    public function updateConfig(string $reportKind, string $token, ?array $columns = null): Response
    {
        $path = $this->configPath($reportKind);
        $columns ??= $this->desiredColumns($reportKind);

        return $this->http->putJson($this->baseUrl().$path, $this->configBody($reportKind, $columns), $token, 30);
    }

    /**
     * @param  list<string>  $columns
     */
    public function createConfig(string $reportKind, string $token, array $columns): Response
    {
        $path = $this->configPath($reportKind);

        return $this->http->postJson($this->baseUrl().$path, $this->configBody($reportKind, $columns), $token, 30);
    }

    public function createReport(string $reportKind, string $token, string $beginDate, string $endDate): Response
    {
        $path = $this->reportPath($reportKind);

        return $this->http->postJson($this->baseUrl().$path, [
            'begin_date' => $beginDate,
            'end_date' => $endDate,
        ], $token, 60);
    }

    public function searchReports(string $reportKind, string $token, string $beginDate, string $endDate): Response
    {
        $path = $this->reportPath($reportKind).'/search';

        return $this->http->get($this->baseUrl().$path, [
            'begin_date' => $beginDate,
            'end_date' => $endDate,
            'limit' => 50,
            'offset' => 0,
        ], $token, 30);
    }

    public function listReports(string $reportKind, string $token, int $limit = 20, int $offset = 0): Response
    {
        $path = $this->reportPath($reportKind).'/list';

        return $this->http->get($this->baseUrl().$path, [
            'limit' => max(1, min(100, $limit)),
            'offset' => max(0, $offset),
        ], $token, 30);
    }

    public function downloadReport(string $reportKind, string $token, string $fileName): Response
    {
        $path = $this->reportPath($reportKind).'/'.$fileName;

        return $this->http->getBinary($this->baseUrl().$path, [], $token, 120);
    }

    public function displayTimezone(): string
    {
        $tz = (string) config('app.business_timezone', 'America/Mexico_City');

        // MP release/settlement config expects GMT-XX labels (MX sellers: GMT-06).
        return match ($tz) {
            'America/Mexico_City', 'America/Monterrey', 'America/Cancun' => 'GMT-06',
            default => 'GMT-06',
        };
    }

    /**
     * @return list<string>
     */
    public function desiredColumns(string $reportKind): array
    {
        $isRelease = in_array($reportKind, ['release', 'released_money'], true);

        return array_values($isRelease
            ? (array) config('finance.mercadopago.release_report_columns', ['DATE', 'SOURCE_ID', 'EXTERNAL_REFERENCE'])
            : (array) config('finance.mercadopago.report_columns', []));
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function configBody(string $reportKind, array $columns): array
    {
        $isRelease = in_array($reportKind, ['release', 'released_money'], true);
        $columnObjects = array_map(fn (string $key) => ['key' => $key], array_values($columns));
        $prefix = 'saas-'.$reportKind.'-report';
        $tz = $this->displayTimezone();

        if ($isRelease) {
            return [
                'file_name_prefix' => $prefix,
                'include_withdrawal_at_end' => true,
                'execute_after_withdrawal' => false,
                'display_timezone' => $tz,
                'frequency' => [
                    'hour' => 0,
                    'type' => 'daily',
                    'value' => '',
                ],
                'columns' => $columnObjects,
            ];
        }

        return [
            'file_name_prefix' => $prefix,
            'include_withdraw' => true,
            'display_timezone' => $tz,
            'header_language' => 'es',
            'frequency' => [
                'hour' => 0,
                'type' => 'monthly',
                'value' => 1,
            ],
            'columns' => $columnObjects,
        ];
    }

    /**
     * @param  mixed  $configJson
     * @param  list<string>  $desired
     */
    private function configMissingColumns(mixed $configJson, array $desired): bool
    {
        if ($desired === [] || ! is_array($configJson)) {
            return false;
        }

        $present = [];
        foreach ($configJson['columns'] ?? [] as $col) {
            if (is_array($col) && isset($col['key']) && is_string($col['key'])) {
                $present[] = $col['key'];
            } elseif (is_string($col)) {
                $present[] = $col;
            }
        }

        foreach ($desired as $key) {
            if (! in_array($key, $present, true)) {
                return true;
            }
        }

        return false;
    }

    private function reportPath(string $reportKind): string
    {
        return match ($reportKind) {
            'settlement', 'account_money' => (string) config('finance.mercadopago.settlement_report_path', '/v1/account/settlement_report'),
            'release', 'released_money' => (string) config('finance.mercadopago.release_report_path', '/v1/account/release_report'),
            default => throw new RuntimeException('Unknown Mercado Pago report kind: '.$reportKind),
        };
    }

    private function configPath(string $reportKind): string
    {
        return $this->reportPath($reportKind).'/config';
    }
}
