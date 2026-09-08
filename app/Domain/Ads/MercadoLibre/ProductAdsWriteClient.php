<?php

namespace App\Domain\Ads\MercadoLibre;

use App\Domain\Integrations\Actions\EnsureFreshConnectionToken;
use App\Integrations\Support\LoggedHttpClient;
use App\Models\AdAdvertiser;
use App\Models\AdCampaign;
use App\Models\Connection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Feature-flagged write client for Product Ads.
 *
 * Maps create/pause/budget/roas_target against marketplace + legacy paths.
 * See docs/ads-write-endpoints.md.
 */
final class ProductAdsWriteClient
{
    public function __construct(
        private readonly LoggedHttpClient $http,
        private readonly EnsureFreshConnectionToken $ensureFreshConnectionToken,
    ) {}

    /**
     * @return array{ok: bool, writable: bool, status: int|null, message: string, tried: list<string>}
     */
    public function probeWriteAccess(Connection $connection, ?AdCampaign $campaign = null): array
    {
        $token = $this->ensureFreshConnectionToken->execute($connection);
        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $siteId = (string) ($connection->site_id ?: 'MLM');

        $campaign ??= AdCampaign::query()
            ->where('connection_id', $connection->id)
            ->orderByDesc('id')
            ->first();

        if ($campaign === null) {
            return [
                'ok' => false,
                'writable' => false,
                'status' => null,
                'message' => 'Sin campañas locales para probar escritura. Sincronizá Ads primero.',
                'tried' => [],
            ];
        }

        $advertiser = $campaign->advertiser;
        $paths = $this->resolvePaths('update_campaign', [
            'site_id' => $siteId,
            'advertiser_id' => (string) ($advertiser?->external_advertiser_id ?? ''),
            'campaign_id' => (string) $campaign->external_campaign_id,
            'item_id' => '',
        ]);

        $tried = [];
        foreach ($paths as $path) {
            $url = $base.$path;
            $tried[] = $url;
            // OPTIONS/HEAD often unsupported; GET campaign detail validates read; PUT with empty body may 400 vs 403.
            $response = $this->http->putJson($url, [], $token, 30, ['api-version' => '2']);
            $status = $response->status();

            if ($status === 403) {
                return [
                    'ok' => false,
                    'writable' => false,
                    'status' => 403,
                    'message' => 'Publicidad write no autorizado (403). Habilitá lectura+escritura en Permisos funcionales y reautorizá.',
                    'tried' => $tried,
                ];
            }

            // 400/422 = endpoint exists but payload invalid → write surface reachable
            if (in_array($status, [200, 204, 400, 422], true)) {
                return [
                    'ok' => true,
                    'writable' => true,
                    'status' => $status,
                    'message' => 'Endpoint de escritura alcanzable.',
                    'tried' => $tried,
                ];
            }

            if ($status !== 404) {
                Log::info('ads.ml.write.probe.unexpected', [
                    'connection_id' => $connection->id,
                    'status' => $status,
                    'url' => $url,
                    'body' => mb_substr($response->body(), 0, 300),
                ]);
            }
        }

        return [
            'ok' => false,
            'writable' => false,
            'status' => 404,
            'message' => 'No se encontró un endpoint write válido (404). El asistente seguirá en modo sugerencia.',
            'tried' => $tried,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: int, body: array<string, mixed>|null, path: string|null, error?: string}
     */
    public function updateCampaign(Connection $connection, AdCampaign $campaign, array $payload): array
    {
        $advertiser = $this->resolveAdvertiser($campaign);

        return $this->mutate(
            $connection,
            'update_campaign',
            [
                'site_id' => (string) ($connection->site_id ?: $advertiser?->site_id ?: 'MLM'),
                'advertiser_id' => (string) ($advertiser?->external_advertiser_id ?? ''),
                'campaign_id' => (string) $campaign->external_campaign_id,
                'item_id' => '',
            ],
            $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: int, body: array<string, mixed>|null, path: string|null, error?: string}
     */
    public function updateAd(Connection $connection, string $mlItemId, array $payload, ?AdAdvertiser $advertiser = null): array
    {
        return $this->mutate(
            $connection,
            'update_ad',
            [
                'site_id' => (string) ($connection->site_id ?: $advertiser?->site_id ?: 'MLM'),
                'advertiser_id' => (string) ($advertiser?->external_advertiser_id ?? ''),
                'campaign_id' => '',
                'item_id' => $mlItemId,
            ],
            $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: int, body: array<string, mixed>|null, path: string|null, error?: string}
     */
    public function createCampaign(Connection $connection, AdAdvertiser $advertiser, array $payload): array
    {
        return $this->mutate(
            $connection,
            'create_campaign',
            [
                'site_id' => (string) ($connection->site_id ?: $advertiser->site_id ?: 'MLM'),
                'advertiser_id' => (string) $advertiser->external_advertiser_id,
                'campaign_id' => '',
                'item_id' => '',
            ],
            $payload,
            method: 'post',
        );
    }

    /**
     * @param  list<string>  $itemIds
     * @return array{ok: bool, status: int, body: array<string, mixed>|null, path: string|null, error?: string}
     */
    public function addCampaignItems(
        Connection $connection,
        AdCampaign $campaign,
        array $itemIds,
        ?AdAdvertiser $advertiser = null,
    ): array {
        $advertiser ??= $this->resolveAdvertiser($campaign);

        return $this->mutate(
            $connection,
            'add_campaign_items',
            [
                'site_id' => (string) ($connection->site_id ?: $advertiser?->site_id ?: 'MLM'),
                'advertiser_id' => (string) ($advertiser?->external_advertiser_id ?? ''),
                'campaign_id' => (string) $campaign->external_campaign_id,
                'item_id' => '',
            ],
            ['items' => array_values(array_map(static fn (string $id) => ['item_id' => $id], $itemIds))],
            method: 'post',
        );
    }

    public function pauseCampaign(Connection $connection, AdCampaign $campaign): array
    {
        return $this->updateCampaign($connection, $campaign, ['status' => 'paused']);
    }

    public function activateCampaign(Connection $connection, AdCampaign $campaign): array
    {
        return $this->updateCampaign($connection, $campaign, ['status' => 'active']);
    }

    public function pauseAd(Connection $connection, string $mlItemId, ?AdAdvertiser $advertiser = null): array
    {
        return $this->updateAd($connection, $mlItemId, ['status' => 'paused'], $advertiser);
    }

    public function activateAd(Connection $connection, string $mlItemId, ?AdAdvertiser $advertiser = null): array
    {
        return $this->updateAd($connection, $mlItemId, ['status' => 'active'], $advertiser);
    }

    /**
     * @param  array{site_id: string, advertiser_id: string, campaign_id: string, item_id: string}  $vars
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: int, body: array<string, mixed>|null, path: string|null, error?: string}
     */
    private function mutate(
        Connection $connection,
        string $endpointKey,
        array $vars,
        array $payload,
        string $method = 'put',
    ): array {
        $token = $this->ensureFreshConnectionToken->execute($connection);
        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $paths = $this->resolvePaths($endpointKey, $vars);
        $lastStatus = 0;
        $lastBody = null;
        $lastPath = null;

        foreach ($paths as $path) {
            $url = $base.$path;
            $lastPath = $path;
            $response = $method === 'post'
                ? $this->http->postJson($url, $payload, $token, 45, ['api-version' => '2'])
                : $this->http->putJson($url, $payload, $token, 45, ['api-version' => '2']);

            $lastStatus = $response->status();
            $json = $response->json();
            $lastBody = is_array($json) ? $json : null;

            if ($response->successful()) {
                return [
                    'ok' => true,
                    'status' => $lastStatus,
                    'body' => $lastBody,
                    'path' => $path,
                ];
            }

            if ($lastStatus === 403) {
                return [
                    'ok' => false,
                    'status' => 403,
                    'body' => $lastBody,
                    'path' => $path,
                    'error' => $this->redactError($response, 'PA_UNAUTHORIZED / write denied'),
                ];
            }

            // Try next path on 404; otherwise stop.
            if ($lastStatus !== 404) {
                break;
            }
        }

        return [
            'ok' => false,
            'status' => $lastStatus,
            'body' => $lastBody,
            'path' => $lastPath,
            'error' => 'Product Ads write failed: '.$lastStatus,
        ];
    }

    /**
     * @param  array{site_id: string, advertiser_id: string, campaign_id: string, item_id: string}  $vars
     * @return list<string>
     */
    private function resolvePaths(string $endpointKey, array $vars): array
    {
        $configured = config('ads.write_endpoints.'.$endpointKey, []);
        if (is_string($configured)) {
            $configured = [$configured];
        }
        if (! is_array($configured)) {
            throw new RuntimeException("Missing ads.write_endpoints.{$endpointKey}");
        }

        $out = [];
        foreach ($configured as $template) {
            if (! is_string($template) || $template === '') {
                continue;
            }
            $path = str_replace(
                ['{site_id}', '{advertiser_id}', '{campaign_id}', '{item_id}'],
                [$vars['site_id'], $vars['advertiser_id'], $vars['campaign_id'], $vars['item_id']],
                $template,
            );
            if (str_contains($path, '//') || str_contains($path, '{}')) {
                continue;
            }
            $out[] = $path;
        }

        return array_values(array_unique($out));
    }

    private function resolveAdvertiser(AdCampaign $campaign): ?AdAdvertiser
    {
        if ($campaign->relationLoaded('advertiser')) {
            return $campaign->advertiser;
        }

        return AdAdvertiser::query()->find($campaign->ad_advertiser_id);
    }

    private function redactError(Response $response, string $fallback): string
    {
        $code = is_array($response->json()) ? (string) ($response->json()['code'] ?? '') : '';

        return $fallback.($code !== '' ? " ({$code})" : '');
    }
}
