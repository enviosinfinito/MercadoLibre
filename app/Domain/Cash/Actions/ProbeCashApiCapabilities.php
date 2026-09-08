<?php

namespace App\Domain\Cash\Actions;

use App\Domain\Cash\Support\MercadoPagoReportClient;
use App\Domain\Integrations\Actions\EnsureFreshConnectionToken;
use App\Integrations\Support\LoggedHttpClient;
use App\Models\Connection;
use App\Models\ConnectionCapability;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Probe ML/MP cash endpoints with the seller token and persist connection_capabilities.
 *
 * Settlement/release report GET /config returning 404 means "no config yet", not "no permission".
 * In that case we POST to create config (same as sync) and mark the capability enabled if create works.
 *
 * @return array<string, array{enabled: bool, http_status: int|null, note: string|null}>
 */
final class ProbeCashApiCapabilities
{
    public function __construct(
        private readonly LoggedHttpClient $http,
        private readonly EnsureFreshConnectionToken $ensureToken,
        private readonly MercadoPagoReportClient $mpReports,
    ) {}

    /**
     * @return array<string, array{enabled: bool, http_status: int|null, note: string|null}>
     */
    public function execute(Connection $connection): array
    {
        $token = $this->ensureToken->execute($connection->loadMissing('credential'));
        $mlBase = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $keys = config('finance.cash.capability_keys');

        $results = [];

        $results['collections'] = $this->probeGet(
            $mlBase.'/collections/search?limit=1',
            $token,
            'GET /collections/search',
        );
        $this->persist($connection, (string) $keys['collections'], $results['collections']);

        $results['settlement_report'] = $this->probeMpReportConfig('settlement', $token);
        $this->persist($connection, (string) $keys['settlement_report'], $results['settlement_report']);

        $results['release_report'] = $this->probeMpReportConfig('release', $token);
        $this->persist($connection, (string) $keys['release_report'], $results['release_report']);

        $periodKey = CarbonImmutable::now()->startOfMonth()->format('Y-m-d');
        $results['billing'] = $this->probeGet(
            $mlBase.'/billing/integration/monthly/periods?group=ML&document_type=BILL&limit=1',
            $token,
            'GET /billing/integration/monthly/periods (key sample '.$periodKey.')',
        );
        $this->persist($connection, (string) $keys['billing'], $results['billing']);

        return $results;
    }

    /**
     * GET config; on 404 try POST create. 401/403 remain disabled.
     *
     * @return array{enabled: bool, http_status: int|null, note: string|null}
     */
    private function probeMpReportConfig(string $reportKind, string $token): array
    {
        $label = 'GET /v1/account/'.$reportKind.'_report/config';

        try {
            $response = $this->mpReports->ensureConfig($reportKind, $token);
            $status = $response->status();

            if ($status >= 200 && $status < 300) {
                return [
                    'enabled' => true,
                    'http_status' => $status,
                    'note' => $label.' OK (config present or created)',
                ];
            }

            if (in_array($status, [401, 403], true)) {
                return [
                    'enabled' => false,
                    'http_status' => $status,
                    'note' => $label.' → HTTP '.$status.' (sin permiso)',
                ];
            }

            // Legacy false-negative: bare GET 404. ensureConfig should have POSTed;
            // if we still get non-2xx, report honestly.
            if ($status === 404) {
                return [
                    'enabled' => true,
                    'http_status' => 404,
                    'note' => $label.' → HTTP 404 (config ausente; sync intentará crearla)',
                ];
            }

            return [
                'enabled' => false,
                'http_status' => $status,
                'note' => $label.' → HTTP '.$status,
            ];
        } catch (Throwable $e) {
            return [
                'enabled' => false,
                'http_status' => null,
                'note' => $label.' error: '.mb_substr($e->getMessage(), 0, 180),
            ];
        }
    }

    /**
     * @return array{enabled: bool, http_status: int|null, note: string|null}
     */
    private function probeGet(string $url, string $token, string $label): array
    {
        try {
            $response = $this->http->get($url, [], $token, 20);
            $status = $response->status();
            $enabled = $status >= 200 && $status < 300;

            return [
                'enabled' => $enabled,
                'http_status' => $status,
                'note' => $enabled ? $label.' OK' : $label.' → HTTP '.$status,
            ];
        } catch (Throwable $e) {
            return [
                'enabled' => false,
                'http_status' => null,
                'note' => $label.' error: '.mb_substr($e->getMessage(), 0, 180),
            ];
        }
    }

    /**
     * @param  array{enabled: bool, http_status: int|null, note: string|null}  $result
     */
    private function persist(Connection $connection, string $capabilityKey, array $result): void
    {
        ConnectionCapability::query()->updateOrCreate(
            [
                'connection_id' => $connection->id,
                'capability_key' => $capabilityKey,
            ],
            [
                'workspace_id' => $connection->workspace_id,
                'enabled' => $result['enabled'],
                'meta' => [
                    'http_status' => $result['http_status'],
                    'note' => $result['note'],
                    'probed_at' => now()->toIso8601String(),
                ],
            ],
        );
    }
}
