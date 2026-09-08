<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ConnectionReputationSnapshot;
use App\Models\EncryptedCredential;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectionReputationEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function enrich_persists_reputation_meta_and_first_sample_milestone(): void
    {
        config([
            'connectors.mercadolibre.api_base_url' => 'https://api.mercadolibre.com',
            'connectors.mercadolibre.client_id' => 'test-client',
            'connectors.mercadolibre.client_secret' => 'test-secret',
        ]);

        $workspace = Workspace::factory()->create();
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '1001',
            'display_name' => null,
            'status' => 'active',
        ]);

        $credential = new EncryptedCredential(['connection_id' => $connection->id]);
        $credential->setPlainPayload([
            'access_token' => 'access-live',
            'refresh_token' => 'refresh-live',
            'expires_at' => now()->addHour()->toIso8601String(),
            'user_id' => '1001',
        ]);
        $credential->save();

        Http::fake([
            'https://api.mercadolibre.com/users/me' => Http::response([
                'id' => 1001,
                'nickname' => 'SHOP_TEST',
                'first_name' => 'Shop',
                'last_name' => 'Test',
                'email' => 'shop@example.com',
                'country_id' => 'MX',
                'user_type' => 'normal',
                'seller_experience' => 'ADVANCED',
                'tags' => ['normal', 'eshop'],
                'permalink' => 'https://perfil.mercadolibre.com.mx/SHOP_TEST',
                'site_id' => 'MLM',
                'logo' => null,
                'phone' => ['area_code' => '55', 'number' => '11112222'],
                'address' => ['city' => 'CDMX', 'state' => 'MX-CMX', 'address' => 'Calle 1'],
                'status' => [
                    'site_status' => 'active',
                    'confirmed_email' => true,
                    'sell' => ['allow' => true, 'codes' => []],
                    'buy' => ['allow' => true, 'codes' => []],
                    'list' => ['allow' => true, 'codes' => []],
                    'billing' => ['allow' => true, 'codes' => []],
                    'mercadoenvios' => 'accepted',
                    'mercadopago_tc_accepted' => true,
                ],
                'buyer_reputation' => [
                    'canceled_transactions' => 0,
                    'tags' => [],
                    'transactions' => ['period' => 'historic', 'total' => 1, 'completed' => 1],
                ],
                'seller_reputation' => [
                    'level_id' => '5_green',
                    'power_seller_status' => 'gold',
                    'transactions' => [
                        'period' => 'historic',
                        'total' => 100,
                        'completed' => 90,
                        'canceled' => 10,
                        'ratings' => [
                            'positive' => 0.9,
                            'neutral' => 0.05,
                            'negative' => 0.05,
                        ],
                    ],
                    'metrics' => [
                        'sales' => ['period' => '60 days', 'completed' => 40],
                        'claims' => ['period' => '60 days', 'rate' => 0.01, 'value' => 1],
                        'cancellations' => ['period' => '60 days', 'rate' => 0.005, 'value' => 1],
                        'delayed_handling_time' => ['period' => '60 days', 'rate' => 0.08, 'value' => 3],
                    ],
                ],
            ], 200),
            'https://api.mercadolibre.com/users/1001/brands' => Http::response(['brands' => []], 200),
        ]);

        $this->artisan('connections:enrich-mercadolibre-profiles --force')
            ->assertSuccessful();

        $connection->refresh();
        $this->assertSame('5_green', $connection->reputation_level);
        $this->assertSame('gold', $connection->power_seller_status);
        $this->assertNotNull($connection->reputation_meta);
        $this->assertSame('MLM', $connection->reputation_meta['evaluation']['site_id']);
        $this->assertNotNull($connection->reputation_synced_at);
        $this->assertSame('shop@example.com', $connection->account_profile['email']);
        $this->assertSame('active', $connection->account_profile['status']['site_status']);
        $this->assertSame(['normal', 'eshop'], $connection->account_profile['tags']);
        $this->assertNotNull($connection->account_profile_synced_at);

        $snap = ConnectionReputationSnapshot::query()->where('connection_id', $connection->id)->first();
        $this->assertNotNull($snap);
        $this->assertTrue($snap->is_milestone);
        $this->assertSame('first_sample', $snap->milestone_kind);
    }

    #[Test]
    public function connection_show_includes_timeline_and_pe_summary(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        WorkspaceMembership::factory()->owner()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
        ]);

        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'reputation_level' => '5_green',
            'power_seller_status' => 'platinum',
            'reputation_meta' => [
                'level_id' => '5_green',
                'evaluation' => ['worst_band' => 'green', 'drivers' => []],
            ],
            'account_profile' => [
                'nickname' => 'SHOP_TEST',
                'email' => 'shop@example.com',
                'status' => ['site_status' => 'active', 'sell' => ['allow' => true]],
            ],
            'account_profile_synced_at' => now(),
            'status' => 'active',
        ]);

        ConnectionReputationSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'capture_date' => now()->toDateString(),
            'captured_at' => now(),
            'level_id' => '5_green',
            'power_seller_status' => 'platinum',
            'worst_band' => 'green',
            'claims_rate' => 0.01,
            'cancellations_rate' => 0.004,
            'delayed_handling_rate' => 0.07,
            'sales_completed' => 40,
            'is_milestone' => true,
            'milestone_kind' => 'first_sample',
            'milestone_label' => 'Primer registro',
            'payload' => ['level_id' => '5_green'],
        ]);

        $this->actingAs($user)
            ->withSession(['workspace_id' => $workspace->id])
            ->getJson(route('connections.show', $connection))
            ->assertOk()
            ->assertJsonPath('connection.reputation_level', '5_green')
            ->assertJsonPath('connection.account_profile.email', 'shop@example.com')
            ->assertJsonPath('reputation_timeline.milestones.0.kind', 'first_sample')
            ->assertJsonStructure([
                'purchase_experience_summary' => [
                    'enabled',
                    'totals_by_color',
                    'without_data',
                    'top_problems',
                ],
                'purchase_experience_listings',
            ]);
    }
}
