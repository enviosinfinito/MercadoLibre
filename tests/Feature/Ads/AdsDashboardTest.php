<?php

namespace Tests\Feature\Ads;

use App\Models\AdAdvertiser;
use App\Models\AdCampaign;
use App\Models\AdSpendDaily;
use App\Models\ChannelListing;
use App\Models\Connection;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdsDashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Connection, 3: Product}
     */
    private function seedAdsWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'display_name' => 'Seller Ads',
            'status' => 'active',
            'site_id' => 'MLM',
        ]);

        $product = Product::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Producto Ads',
        ]);

        ChannelListing::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'external_item_id' => 'MLM999',
            'product_id' => $product->id,
            'title' => 'Listing Ads',
            'status' => 'active',
        ]);

        $advertiser = AdAdvertiser::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_advertiser_id' => '100',
            'site_id' => 'MLM',
            'name' => 'Adv',
        ]);
        $campaign = AdCampaign::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'ad_advertiser_id' => $advertiser->id,
            'external_campaign_id' => '200',
            'name' => 'Campaña Top',
        ]);

        AdSpendDaily::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'ad_advertiser_id' => $advertiser->id,
            'ad_campaign_id' => $campaign->id,
            'external_campaign_id' => '200',
            'ml_item_id' => 'MLM999',
            'date' => now()->subDays(2)->toDateString(),
            'cost' => '50.000000',
            'clicks' => 25,
            'prints' => 1000,
            'total_amount' => '200.000000',
            'currency_code' => 'MXN',
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$user, $workspace, $connection, $product];
    }

    #[Test]
    public function dashboard_renders_with_metrics(): void
    {
        $this->seedAdsWorkspace();

        $this->get(route('ads.dashboard', ['period' => 'last_30_days', 'group_by' => 'campaign']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Ads/Dashboard')
                ->where('metrics.has_data', true)
                ->where('metrics.kpis.cost', 50)
                ->where('metrics.kpis.clicks', 25)
                ->has('metrics.breakdown')
                ->has('syncStatus'));
    }

    #[Test]
    public function dashboard_flags_permission_blocked_from_failed_sync(): void
    {
        [, $workspace, $connection] = $this->seedAdsWorkspace();

        \App\Models\SyncRun::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'resource_type' => 'ads',
            'mode' => 'bootstrap',
            'status' => 'failed',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'error_redacted' => 'Mercado Libre rechazó Product Ads (403 PA_UNAUTHORIZED_RESULT_FROM_POLICIES). En Developers → Permisos funcionales, habilita «Publicidad».',
            'stats' => ['error_code' => 'PA_UNAUTHORIZED_RESULT_FROM_POLICIES'],
        ]);

        // Empty spend so empty-state path is used with permission banner.
        \App\Models\AdSpendDaily::query()->delete();

        $this->get(route('ads.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Ads/Dashboard')
                ->where('metrics.has_data', false)
                ->where('syncStatus.permission_blocked', true));
    }

    #[Test]
    public function product_slide_context_exposes_ads_tab(): void
    {
        [, , , $product] = $this->seedAdsWorkspace();

        $this->getJson(route('catalog.product-slide-context', ['product_id' => $product->id]))
            ->assertOk()
            ->assertJsonPath('tabs.ads', true);
    }

    #[Test]
    public function product_ads_summary_endpoint_returns_kpis(): void
    {
        [, , , $product] = $this->seedAdsWorkspace();

        $this->getJson(route('products.ads', [
            'product' => $product->id,
            'period' => 'last_30_days',
        ]))
            ->assertOk()
            ->assertJsonPath('has_data', true)
            ->assertJsonPath('summary.cost', 50)
            ->assertJsonPath('product_id', $product->id)
            ->assertJsonStructure(['series', 'by_campaign', 'by_item', 'dashboard_url']);
    }
}
