<?php

namespace App\Domain\Ads\Actions;

use App\Domain\Integrations\Actions\EnsureFreshConnectionToken;
use App\Integrations\Support\LoggedHttpClient;
use App\Models\AdAdvertiser;
use App\Models\AdCampaign;
use App\Models\AdSpendDaily;
use App\Models\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sync Product Ads advertisers, campaigns and daily item spend from Mercado Libre.
 */
final class SyncMercadoLibreProductAds
{
    private const METRICS = 'clicks,prints,ctr,cost,cpc,acos,roas,direct_amount,indirect_amount,total_amount,direct_units_quantity,indirect_units_quantity,advertising_items_quantity,organic_units_quantity,organic_units_amount,organic_items_quantity,units_quantity,direct_items_quantity,indirect_items_quantity';

    public function __construct(
        private readonly LoggedHttpClient $http,
        private readonly EnsureFreshConnectionToken $ensureFreshConnectionToken,
    ) {}

    /**
     * @param  array{date_from?: string, date_to?: string, dry_run?: bool}  $options
     * @return array{
     *     ok: bool,
     *     connection_id: int,
     *     advertisers: int,
     *     campaigns: int,
     *     spend_rows: int,
     *     spend: float,
     *     date_from: string,
     *     date_to: string,
     *     dry_run: bool
     * }
     */
    public function execute(Connection $connection, array $options = []): array
    {
        $dateTo = Carbon::parse($options['date_to'] ?? now()->toDateString())->startOfDay();
        $dateFrom = Carbon::parse(
            $options['date_from']
                ?? $dateTo->copy()->subDays(max(1, (int) config('ads.sync_lookback_days', 90)) - 1)->toDateString()
        )->startOfDay();
        $dryRun = (bool) ($options['dry_run'] ?? false);

        $token = $this->ensureFreshConnectionToken->execute($connection);
        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');

        $advertisers = $this->fetchAdvertisers($base, $token);
        $advertiserCount = 0;
        $campaignCount = 0;
        $spendRows = 0;
        $spendTotal = '0';

        if ($advertisers === []) {
            Log::info('ads.ml.product_ads.sync.empty_advertisers', [
                'workspace_id' => $connection->workspace_id,
                'connection_id' => $connection->id,
            ]);
        }

        foreach ($advertisers as $row) {
            $externalAdvertiserId = (string) ($row['advertiser_id'] ?? '');
            if ($externalAdvertiserId === '') {
                continue;
            }

            $advertiserCount++;
            $siteId = isset($row['site_id']) ? (string) $row['site_id'] : (string) ($connection->site_id ?? 'MLM');
            if ($siteId === '') {
                $siteId = 'MLM';
            }

            if ($dryRun) {
                continue;
            }

            $advertiser = AdAdvertiser::query()->updateOrCreate(
                [
                    'connection_id' => $connection->id,
                    'external_advertiser_id' => $externalAdvertiserId,
                ],
                [
                    'workspace_id' => $connection->workspace_id,
                    'site_id' => $siteId,
                    'name' => isset($row['advertiser_name']) ? (string) $row['advertiser_name'] : null,
                    'account_name' => isset($row['account_name']) ? (string) $row['account_name'] : null,
                    'meta' => $row,
                ],
            );

            $campaigns = $this->fetchCampaigns($base, $token, $siteId, $externalAdvertiserId, $dateFrom, $dateTo);
            foreach ($campaigns as $campaignRow) {
                $externalCampaignId = (string) ($campaignRow['id'] ?? $campaignRow['campaign_id'] ?? '');
                if ($externalCampaignId === '') {
                    continue;
                }
                $campaignCount++;

                AdCampaign::query()->updateOrCreate(
                    [
                        'connection_id' => $connection->id,
                        'external_campaign_id' => $externalCampaignId,
                    ],
                    [
                        'workspace_id' => $connection->workspace_id,
                        'ad_advertiser_id' => $advertiser->id,
                        'name' => isset($campaignRow['name']) ? (string) $campaignRow['name'] : null,
                        'status' => isset($campaignRow['status']) ? (string) $campaignRow['status'] : null,
                        'strategy' => isset($campaignRow['strategy']) ? (string) $campaignRow['strategy'] : null,
                        'meta' => $campaignRow,
                    ],
                );
            }

            $itemMetrics = $this->fetchItemDailyMetrics(
                $base,
                $token,
                $siteId,
                $externalAdvertiserId,
                $dateFrom,
                $dateTo,
            );

            foreach ($itemMetrics as $metric) {
                $mlItemId = (string) ($metric['item_id'] ?? $metric['id'] ?? '');
                if ($mlItemId === '') {
                    continue;
                }

                $date = $this->resolveMetricDate($metric, $dateFrom);
                $externalCampaignId = (string) ($metric['campaign_id'] ?? '');
                $metrics = is_array($metric['metrics'] ?? null) ? $metric['metrics'] : $metric;

                $cost = $this->decimal($metrics['cost'] ?? 0);
                $spendTotal = bcadd($spendTotal, $cost, 6);

                $campaign = null;
                if ($externalCampaignId !== '') {
                    $campaign = AdCampaign::query()
                        ->where('connection_id', $connection->id)
                        ->where('external_campaign_id', $externalCampaignId)
                        ->first();

                    if ($campaign === null) {
                        $campaign = AdCampaign::query()->create([
                            'workspace_id' => $connection->workspace_id,
                            'connection_id' => $connection->id,
                            'ad_advertiser_id' => $advertiser->id,
                            'external_campaign_id' => $externalCampaignId,
                            'name' => isset($metric['campaign_name']) ? (string) $metric['campaign_name'] : null,
                            'status' => null,
                            'strategy' => null,
                            'meta' => null,
                        ]);
                        $campaignCount++;
                    }
                }

                AdSpendDaily::query()->updateOrCreate(
                    [
                        'workspace_id' => $connection->workspace_id,
                        'connection_id' => $connection->id,
                        'external_campaign_id' => $externalCampaignId,
                        'ml_item_id' => $mlItemId,
                        'date' => $date,
                    ],
                    [
                        'ad_advertiser_id' => $advertiser->id,
                        'ad_campaign_id' => $campaign?->id,
                        'cost' => $cost,
                        'clicks' => (int) ($metrics['clicks'] ?? 0),
                        'prints' => (int) ($metrics['prints'] ?? 0),
                        'cpc' => $this->nullableDecimal($metrics['cpc'] ?? null),
                        'ctr' => $this->nullableDecimal($metrics['ctr'] ?? null),
                        'direct_amount' => $this->decimal($metrics['direct_amount'] ?? 0),
                        'indirect_amount' => $this->decimal($metrics['indirect_amount'] ?? 0),
                        'total_amount' => $this->decimal($metrics['total_amount'] ?? 0),
                        'direct_units_quantity' => $this->decimal($metrics['direct_units_quantity'] ?? 0),
                        'indirect_units_quantity' => $this->decimal($metrics['indirect_units_quantity'] ?? 0),
                        'advertising_items_quantity' => $this->decimal($metrics['advertising_items_quantity'] ?? 0),
                        'organic_units_quantity' => $this->decimal($metrics['organic_units_quantity'] ?? 0),
                        'organic_units_amount' => $this->decimal($metrics['organic_units_amount'] ?? 0),
                        'organic_items_quantity' => $this->decimal($metrics['organic_items_quantity'] ?? 0),
                        'units_quantity' => $this->decimal($metrics['units_quantity'] ?? 0),
                        'direct_items_quantity' => $this->decimal($metrics['direct_items_quantity'] ?? 0),
                        'indirect_items_quantity' => $this->decimal($metrics['indirect_items_quantity'] ?? 0),
                        'acos' => $this->nullableDecimal($metrics['acos'] ?? null),
                        'roas' => $this->nullableDecimal($metrics['roas'] ?? null),
                        'currency_code' => $this->currencyForSite($siteId),
                        'raw' => $metric,
                    ],
                );
                $spendRows++;
            }
        }

        Log::info('ads.ml.product_ads.sync', [
            'workspace_id' => $connection->workspace_id,
            'connection_id' => $connection->id,
            'advertisers' => $advertiserCount,
            'campaigns' => $campaignCount,
            'spend_rows' => $spendRows,
            'spend' => (float) $spendTotal,
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'dry_run' => $dryRun,
        ]);

        return [
            'ok' => true,
            'connection_id' => (int) $connection->id,
            'advertisers' => $advertiserCount,
            'campaigns' => $campaignCount,
            'spend_rows' => $spendRows,
            'spend' => (float) $spendTotal,
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'dry_run' => $dryRun,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAdvertisers(string $base, string $token): array
    {
        $response = $this->http->get(
            $base.'/advertising/advertisers',
            ['product_id' => 'PADS'],
            $token,
            45,
            ['api-version' => '2'],
        );

        if ($response->status() === 403) {
            $code = is_array($response->json()) ? (string) ($response->json()['code'] ?? '') : '';
            Log::warning('ads.ml.advertisers.unauthorized', [
                'status' => 403,
                'code' => $code,
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            throw new RuntimeException(
                'Mercado Libre rechazó Product Ads (403'
                .($code !== '' ? ' '.$code : '')
                .'). En Developers → tu aplicación → Permisos funcionales, habilita «Publicidad» (lectura). '
                .'Luego reautorizá la conexión y volvé a sincronizar Ads.'
            );
        }

        if ($response->status() === 404) {
            Log::warning('ads.ml.advertisers.unavailable', [
                'status' => 404,
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            // Seller without Product Ads product enabled — not an app permission error.
            return [];
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'MercadoLibre advertising advertisers failed: '.$response->status().' '.$response->body()
            );
        }

        $payload = $response->json() ?? [];
        $list = $payload['advertisers'] ?? $payload;
        if (! is_array($list)) {
            return [];
        }

        return array_values(array_filter($list, 'is_array'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchCampaigns(
        string $base,
        string $token,
        string $siteId,
        string $advertiserId,
        Carbon $dateFrom,
        Carbon $dateTo,
    ): array {
        $paths = [
            $base.'/marketplace/advertising/'.$siteId.'/advertisers/'.$advertiserId.'/product_ads/campaigns/search',
            $base.'/advertising/advertisers/'.$advertiserId.'/product_ads/campaigns',
        ];

        foreach ($paths as $path) {
            $results = $this->paginateResults(
                $path,
                [
                    'limit' => 50,
                    'date_from' => $dateFrom->toDateString(),
                    'date_to' => $dateTo->toDateString(),
                    'metrics' => self::METRICS,
                ],
                $token,
                'campaigns',
            );

            if ($results !== null) {
                return $results;
            }
        }

        Log::warning('ads.ml.campaigns.unavailable', [
            'advertiser_id' => $advertiserId,
            'site_id' => $siteId,
        ]);

        return [];
    }

    /**
     * Item-level daily metrics via marketplace ads/search + per-item daily detail.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchItemDailyMetrics(
        string $base,
        string $token,
        string $siteId,
        string $advertiserId,
        Carbon $dateFrom,
        Carbon $dateTo,
    ): array {
        $ads = $this->fetchAdsPeriodSummaries($base, $token, $siteId, $advertiserId, $dateFrom, $dateTo);
        if ($ads === []) {
            return [];
        }

        $results = [];
        $dailyFetched = 0;
        $maxDailyFetches = (int) config('ads.sync_max_item_daily_fetches', 150);

        foreach ($ads as $ad) {
            $mlItemId = (string) ($ad['item_id'] ?? $ad['id'] ?? '');
            if ($mlItemId === '') {
                continue;
            }

            $periodMetrics = is_array($ad['metrics'] ?? null) ? $ad['metrics'] : [];
            $periodCost = (float) ($periodMetrics['cost'] ?? $ad['cost'] ?? 0);
            $periodClicks = (int) ($periodMetrics['clicks'] ?? $ad['clicks'] ?? 0);

            // Zero-activity ads: skip daily detail (saves API calls).
            if ($periodCost <= 0 && $periodClicks <= 0) {
                continue;
            }

            if ($dailyFetched < $maxDailyFetches) {
                $dailyRows = $this->fetchAdDailyMetrics($base, $token, $siteId, $mlItemId, $dateFrom, $dateTo);
                $dailyFetched++;

                if ($dailyRows !== []) {
                    foreach ($dailyRows as $day) {
                        $results[] = array_merge($ad, [
                            'item_id' => $mlItemId,
                            'metrics' => $day,
                            'date' => $day['date'] ?? null,
                        ]);
                    }

                    continue;
                }
            }

            // Fallback: store period totals on end date.
            $results[] = array_merge($ad, [
                'item_id' => $mlItemId,
                'metrics' => $periodMetrics !== [] ? $periodMetrics : $ad,
                'date' => $dateTo->toDateString(),
            ]);
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAdsPeriodSummaries(
        string $base,
        string $token,
        string $siteId,
        string $advertiserId,
        Carbon $dateFrom,
        Carbon $dateTo,
    ): array {
        $paths = [
            $base.'/marketplace/advertising/'.$siteId.'/advertisers/'.$advertiserId.'/product_ads/ads/search',
            $base.'/advertising/advertisers/'.$advertiserId.'/product_ads/items',
        ];

        foreach ($paths as $path) {
            $results = $this->paginateResults(
                $path,
                [
                    'limit' => 50,
                    'date_from' => $dateFrom->toDateString(),
                    'date_to' => $dateTo->toDateString(),
                    'metrics' => self::METRICS,
                ],
                $token,
                'ads',
            );

            if ($results !== null) {
                return $results;
            }
        }

        Log::warning('ads.ml.ads_search.unavailable', [
            'advertiser_id' => $advertiserId,
            'site_id' => $siteId,
        ]);

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAdDailyMetrics(
        string $base,
        string $token,
        string $siteId,
        string $mlItemId,
        Carbon $dateFrom,
        Carbon $dateTo,
    ): array {
        $url = $base.'/marketplace/advertising/'.$siteId.'/product_ads/ads/'.$mlItemId;
        $response = $this->http->get(
            $url,
            [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'metrics' => self::METRICS,
                'aggregation_type' => 'DAILY',
            ],
            $token,
            45,
            ['api-version' => '2'],
        );

        if ($response->failed()) {
            return [];
        }

        $payload = $response->json() ?? [];
        $rows = $payload['results'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * Paginate a Product Ads list endpoint. Returns null when the path is unavailable (404/403).
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>|null
     */
    private function paginateResults(string $url, array $query, string $token, string $label): ?array
    {
        $results = [];
        $offset = 0;
        $limit = (int) ($query['limit'] ?? 50);

        do {
            $response = $this->http->get(
                $url,
                array_merge($query, [
                    'limit' => $limit,
                    'offset' => $offset,
                ]),
                $token,
                60,
                ['api-version' => '2'],
            );

            if ($response->status() === 403 || $response->status() === 404) {
                return $offset === 0 ? null : $results;
            }

            if ($response->failed()) {
                throw new RuntimeException(
                    "MercadoLibre product_ads {$label} failed: ".$response->status().' '.$response->body()
                );
            }

            $payload = $response->json() ?? [];
            $page = $payload['results'] ?? $payload['campaigns'] ?? $payload['ads'] ?? [];
            if (! is_array($page)) {
                break;
            }

            // Account-level DAILY rollup (no item_id) — treat as unavailable for this path.
            if ($page !== [] && $label === 'ads' && ! isset($page[0]['item_id']) && ! isset($page[0]['id'])) {
                return null;
            }

            foreach ($page as $row) {
                if (is_array($row)) {
                    $results[] = $row;
                }
            }

            $total = (int) ($payload['paging']['total'] ?? count($page));
            $offset += $limit;
            $done = $offset >= $total || $page === [];
        } while (! $done);

        return $results;
    }

    /**
     * @param  array<string, mixed>  $metric
     */
    private function resolveMetricDate(array $metric, Carbon $fallback): string
    {
        $raw = $metric['date']
            ?? ($metric['metrics']['date'] ?? null)
            ?? null;

        if (is_string($raw) && $raw !== '') {
            return Carbon::parse($raw)->toDateString();
        }

        return $fallback->toDateString();
    }

    private function decimal(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '0.000000';
        }

        return bcadd((string) $value, '0', 6);
    }

    private function nullableDecimal(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        return bcadd((string) $value, '0', 6);
    }

    private function currencyForSite(?string $siteId): string
    {
        return match (strtoupper((string) $siteId)) {
            'MLA' => 'ARS',
            'MLB' => 'BRL',
            'MLC' => 'CLP',
            'MLU' => 'UYU',
            'MCO' => 'COP',
            'MLM' => 'MXN',
            default => 'MXN',
        };
    }
}
