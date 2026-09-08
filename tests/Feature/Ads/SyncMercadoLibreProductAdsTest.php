<?php

namespace Tests\Feature\Ads;

use App\Domain\Ads\Actions\SyncMercadoLibreProductAds;
use App\Models\AdSpendDaily;
use App\Models\Connection;
use App\Models\EncryptedCredential;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SyncMercadoLibreProductAdsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'connectors.mercadolibre.api_base_url' => 'https://api.mercadolibre.com',
        ]);
    }

    #[Test]
    public function syncs_advertisers_campaigns_and_daily_spend(): void
    {
        $workspace = Workspace::factory()->create();
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
            'site_id' => 'MLM',
        ]);

        $credential = new EncryptedCredential(['connection_id' => $connection->id]);
        $credential->setPlainPayload([
            'access_token' => 'test-token',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]);
        $credential->save();

        $metricDate = now()->subDay()->toDateString();

        Http::fake(function (Request $request) use ($metricDate) {
            $url = $request->url();

            if (str_contains($url, '/advertising/advertisers') && ! str_contains($url, '/product_ads/') && ! str_contains($url, '/marketplace/')) {
                return Http::response([
                    'advertisers' => [[
                        'advertiser_id' => 555,
                        'site_id' => 'MLM',
                        'advertiser_name' => 'Test Adv',
                        'account_name' => 'Acct',
                    ]],
                ], 200);
            }

            if (str_contains($url, '/product_ads/campaigns/search') || str_contains($url, '/product_ads/campaigns')) {
                return Http::response([
                    'results' => [[
                        'id' => 777,
                        'name' => 'Camp Test',
                        'status' => 'active',
                        'strategy' => 'PROFITABILITY',
                    ]],
                    'paging' => ['total' => 1],
                ], 200);
            }

            if (str_contains($url, '/product_ads/ads/MLM555')) {
                return Http::response([
                    'results' => [[
                        'date' => $metricDate,
                        'cost' => 12.5,
                        'clicks' => 5,
                        'prints' => 200,
                        'cpc' => 2.5,
                        'total_amount' => 80,
                        'direct_amount' => 60,
                        'indirect_amount' => 20,
                        'acos' => 0.156,
                        'roas' => 6.4,
                        'direct_units_quantity' => 1,
                        'indirect_units_quantity' => 0,
                    ]],
                ], 200);
            }

            if (str_contains($url, '/product_ads/ads/search') || str_contains($url, '/product_ads/items')) {
                return Http::response([
                    'results' => [[
                        'item_id' => 'MLM555',
                        'campaign_id' => 777,
                        'metrics' => [
                            'cost' => 12.5,
                            'clicks' => 5,
                            'prints' => 200,
                            'cpc' => 2.5,
                            'total_amount' => 80,
                            'direct_amount' => 60,
                            'indirect_amount' => 20,
                            'acos' => 0.156,
                            'roas' => 6.4,
                        ],
                    ]],
                    'paging' => ['total' => 1],
                ], 200);
            }

            return Http::response(['error' => 'unmocked '.$url], 500);
        });

        $result = app(SyncMercadoLibreProductAds::class)->execute($connection->fresh(['credential']), [
            'date_from' => now()->subDays(7)->toDateString(),
            'date_to' => now()->toDateString(),
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['advertisers']);
        $this->assertGreaterThanOrEqual(1, $result['spend_rows']);
        $this->assertSame(1, AdSpendDaily::query()->count());
        $this->assertSame('12.500000', (string) AdSpendDaily::query()->first()->cost);
        $this->assertSame('MLM555', AdSpendDaily::query()->first()->ml_item_id);
    }

    #[Test]
    public function throws_clear_error_when_advertisers_forbidden(): void
    {
        $workspace = Workspace::factory()->create();
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
            'site_id' => 'MLM',
        ]);

        $credential = new EncryptedCredential(['connection_id' => $connection->id]);
        $credential->setPlainPayload([
            'access_token' => 'test-token',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]);
        $credential->save();

        Http::fake([
            'https://api.mercadolibre.com/advertising/advertisers*' => Http::response([
                'message' => 'At least one policy returned UNAUTHORIZED.',
                'status' => 403,
                'code' => 'PA_UNAUTHORIZED_RESULT_FROM_POLICIES',
            ], 403),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Permisos funcionales');

        app(SyncMercadoLibreProductAds::class)->execute($connection->fresh(['credential']), [
            'date_from' => now()->subDays(7)->toDateString(),
            'date_to' => now()->toDateString(),
        ]);
    }
}
