<?php

namespace Tests\Feature\Ads;

use App\Domain\Ads\Services\AdsProfitabilityService;
use App\Domain\Ads\Services\AdsRulePresetInstaller;
use App\Domain\Ads\Services\AdsRulesEvaluator;
use App\Models\AdActionProposal;
use App\Models\AdAdvertiser;
use App\Models\AdCampaign;
use App\Models\AdRule;
use App\Models\AdSpendDaily;
use App\Models\AdWorkspaceSetting;
use App\Models\ChannelListing;
use App\Models\Connection;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdsAssistantTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Connection}
     */
    private function seedWorkspace(): array
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
            'name' => 'Campaña',
            'status' => 'active',
            'meta' => ['budget' => 100, 'roas_target' => 4],
        ]);

        AdSpendDaily::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'ad_advertiser_id' => $advertiser->id,
            'ad_campaign_id' => $campaign->id,
            'external_campaign_id' => '200',
            'ml_item_id' => 'MLM999',
            'date' => now()->subDays(2)->toDateString(),
            'cost' => '80.000000',
            'clicks' => 20,
            'prints' => 1000,
            'total_amount' => '0.000000',
            'direct_units_quantity' => 0,
            'indirect_units_quantity' => 0,
            'currency_code' => 'MXN',
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$user, $workspace, $connection];
    }

    #[Test]
    public function assistant_page_renders(): void
    {
        $this->seedWorkspace();

        $this->get(route('ads.assistant'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Ads/Assistant')
                ->has('scorecard')
                ->has('presets'));
    }

    #[Test]
    public function setup_installs_preset_and_creates_waste_proposal(): void
    {
        [, $workspace] = $this->seedWorkspace();

        $this->post(route('ads.assistant.setup'), [
            'preset' => 'protect_profit',
            'autopilot_mode' => 'shadow',
        ])
            ->assertRedirect(route('ads.assistant'));

        $this->assertDatabaseHas('ad_workspace_settings', [
            'workspace_id' => $workspace->id,
            'active_preset' => 'protect_profit',
            'autopilot_mode' => 'shadow',
        ]);

        $this->assertTrue(
            AdRule::query()->where('workspace_id', $workspace->id)->where('enabled', true)->exists()
        );

        $this->assertTrue(
            AdActionProposal::query()
                ->where('workspace_id', $workspace->id)
                ->where('action_type', 'pause_ad')
                ->where('status', 'pending')
                ->exists()
        );
    }

    #[Test]
    public function profitability_service_flags_waste_and_suggests_target_roas(): void
    {
        [, $workspace] = $this->seedWorkspace();

        $card = app(AdsProfitabilityService::class)->scorecard((int) $workspace->id);

        $this->assertGreaterThan(0, $card['kpis']['waste_spend']);
        $this->assertGreaterThanOrEqual(1, $card['catalog_target_roas']);
        $this->assertSame('red', $card['scorecard'][0]['status'] ?? null);
        $this->assertSame('Actuar', $card['scorecard'][0]['status_label'] ?? null);
        $this->assertNotEmpty($card['scorecard'][0]['status_reason'] ?? null);
    }

    #[Test]
    public function low_margin_cap_does_not_force_actuar_when_roas_is_near_default_target(): void
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

        $advertiser = AdAdvertiser::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_advertiser_id' => '101',
            'site_id' => 'MLM',
            'name' => 'Adv',
        ]);

        $campaign = AdCampaign::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'ad_advertiser_id' => $advertiser->id,
            'external_campaign_id' => '201',
            'name' => 'Campaña',
            'status' => 'active',
            'meta' => ['budget' => 100, 'roas_target' => 4],
        ]);

        // 8.5x ROAS: would look catastrophic vs a raw 35x cap, but OK vs default ~11.4x meta.
        AdSpendDaily::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'ad_advertiser_id' => $advertiser->id,
            'ad_campaign_id' => $campaign->id,
            'external_campaign_id' => '201',
            'ml_item_id' => 'MLM888',
            'date' => now()->subDays(2)->toDateString(),
            'cost' => '100.000000',
            'clicks' => 40,
            'prints' => 2000,
            'total_amount' => '850.000000',
            'direct_units_quantity' => 5,
            'indirect_units_quantity' => 0,
            'currency_code' => 'MXN',
        ]);

        $card = app(AdsProfitabilityService::class)->scorecard((int) $workspace->id);
        $row = $card['scorecard'][0];

        $this->assertTrue((bool) ($row['target_unreliable'] ?? false));
        $this->assertLessThan(20, (float) $row['target_roas']);
        $this->assertNotSame('red', $row['status']);
        $this->assertNotSame('Actuar', $row['status_label']);
        $this->assertNotEmpty($row['status_reason'] ?? null);
        $this->assertSame('margen_default', $row['target_roas_source'] ?? null);
    }

    #[Test]
    public function approve_proposal_simulates_in_shadow_mode(): void
    {
        [$user, $workspace] = $this->seedWorkspace();

        app(AdsRulePresetInstaller::class)->install((int) $workspace->id, 'protect_profit');
        app(AdsRulesEvaluator::class)->execute((int) $workspace->id);

        $proposal = AdActionProposal::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'pending')
            ->firstOrFail();

        AdWorkspaceSetting::forWorkspace((int) $workspace->id)->forceFill([
            'autopilot_mode' => 'shadow',
            'write_enabled' => false,
        ])->save();

        $this->post(route('ads.assistant.proposals.approve', $proposal), ['execute' => true])
            ->assertRedirect();

        $this->assertDatabaseHas('ad_action_executions', [
            'workspace_id' => $workspace->id,
            'ad_action_proposal_id' => $proposal->id,
            'status' => 'simulated',
            'wrote_to_ml' => 0,
        ]);
    }

    #[Test]
    public function campaign_wizard_simulates_when_write_disabled(): void
    {
        [$user, $workspace, $connection] = $this->seedWorkspace();

        $this->post(route('ads.assistant.campaigns.create'), [
            'connection_id' => $connection->id,
            'name' => 'Test Wizard',
            'daily_budget' => 250,
            'roas_target' => 6,
            'item_ids' => ['MLM999'],
        ])
            ->assertRedirect();

        $this->assertDatabaseHas('ad_action_executions', [
            'workspace_id' => $workspace->id,
            'action_type' => 'create_campaign',
            'status' => 'simulated',
            'wrote_to_ml' => 0,
        ]);
    }

    #[Test]
    public function item_detail_includes_campaign_listings_spend_split(): void
    {
        [, $workspace, $connection] = $this->seedWorkspace();

        $campaign = AdCampaign::query()
            ->where('workspace_id', $workspace->id)
            ->where('external_campaign_id', '200')
            ->firstOrFail();

        $advertiserId = $campaign->ad_advertiser_id;

        ChannelListing::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'external_item_id' => 'MLM999',
            'title' => 'Producto A',
            'status' => 'active',
            'permalink' => 'https://articulo.mercadolibre.com.mx/MLM999',
        ]);

        ChannelListing::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'external_item_id' => 'MLM888',
            'title' => 'Producto B',
            'status' => 'active',
            'permalink' => 'https://articulo.mercadolibre.com.mx/MLM888',
        ]);

        AdSpendDaily::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'ad_advertiser_id' => $advertiserId,
            'ad_campaign_id' => $campaign->id,
            'external_campaign_id' => '200',
            'ml_item_id' => 'MLM888',
            'date' => now()->subDays(2)->toDateString(),
            'cost' => '20.000000',
            'clicks' => 5,
            'prints' => 400,
            'total_amount' => '100.000000',
            'direct_units_quantity' => 1,
            'indirect_units_quantity' => 0,
            'currency_code' => 'MXN',
        ]);

        $response = $this->getJson(route('ads.assistant.items.show', [
            'mlItemId' => 'MLM999',
            'days' => 14,
            'connection_id' => $connection->id,
        ]));

        $response->assertOk()
            ->assertJsonPath('ml_item_id', 'MLM999')
            ->assertJsonPath('campaign_listings.listing_count', 2)
            ->assertJsonPath('campaign_listings.other_count', 1)
            ->assertJsonPath('campaign_listings.total_cost', 100);

        $listings = collect($response->json('campaign_listings.listings'));
        $this->assertTrue((bool) $listings->firstWhere('ml_item_id', 'MLM999')['is_current']);
        $this->assertSame(0.8, $listings->firstWhere('ml_item_id', 'MLM999')['share_of_spend']);
        $this->assertSame(0.2, $listings->firstWhere('ml_item_id', 'MLM888')['share_of_spend']);
        $this->assertSame('Producto B', $listings->firstWhere('ml_item_id', 'MLM888')['title']);
    }
}
