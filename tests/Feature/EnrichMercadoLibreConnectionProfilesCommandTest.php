<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\EncryptedCredential;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnrichMercadoLibreConnectionProfilesCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function command_enriches_connections_missing_display_name(): void
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
            'external_user_id' => '1522113841',
            'site_id' => null,
            'display_name' => null,
            'permalink' => null,
            'status' => 'active',
        ]);

        $credential = new EncryptedCredential(['connection_id' => $connection->id]);
        $credential->setPlainPayload([
            'access_token' => 'access-live',
            'refresh_token' => 'refresh-live',
            'expires_in' => 3600,
            'expires_at' => now()->addHour()->toIso8601String(),
            'user_id' => '1522113841',
        ]);
        $credential->save();

        $alreadyEnriched = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '999',
            'display_name' => 'ALREADY_SET',
            'status' => 'active',
        ]);

        Http::fake([
            'https://api.mercadolibre.com/users/me' => Http::response([
                'id' => 1522113841,
                'nickname' => 'CUENTA_UNO',
                'permalink' => 'https://perfil.mercadolibre.com.mx/CUENTA_UNO',
                'site_id' => 'MLM',
                'logo' => null,
                'seller_reputation' => [
                    'level_id' => '4_light_green',
                    'power_seller_status' => 'silver',
                ],
            ], 200),
            'https://api.mercadolibre.com/users/1522113841/brands' => Http::response([
                'brands' => [
                    ['picture_url' => 'https://http2.mlstatic.com/store-avatar.jpg'],
                ],
            ], 200),
        ]);

        $this->artisan('connections:enrich-mercadolibre-profiles')
            ->assertSuccessful();

        $connection->refresh();
        $this->assertSame('CUENTA_UNO', $connection->display_name);
        $this->assertSame('https://perfil.mercadolibre.com.mx/CUENTA_UNO', $connection->permalink);
        $this->assertSame('MLM', $connection->site_id);
        $this->assertSame('https://http2.mlstatic.com/store-avatar.jpg', $connection->avatar_url);
        $this->assertSame('4_light_green', $connection->reputation_level);
        $this->assertSame('silver', $connection->power_seller_status);

        $this->assertSame('ALREADY_SET', $alreadyEnriched->fresh()->display_name);
    }

    #[Test]
    public function enrich_keeps_reputation_when_brands_are_empty(): void
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
            'external_user_id' => '777',
            'display_name' => null,
            'status' => 'active',
        ]);

        $credential = new EncryptedCredential(['connection_id' => $connection->id]);
        $credential->setPlainPayload([
            'access_token' => 'access-live',
            'refresh_token' => 'refresh-live',
            'expires_at' => now()->addHour()->toIso8601String(),
            'user_id' => '777',
        ]);
        $credential->save();

        Http::fake([
            'https://api.mercadolibre.com/users/me' => Http::response([
                'id' => 777,
                'nickname' => 'SIN_TIENDA',
                'permalink' => 'https://perfil.mercadolibre.com.mx/SIN_TIENDA',
                'site_id' => 'MLM',
                'logo' => 'abc123logo',
                'seller_reputation' => [
                    'level_id' => '5_green',
                    'power_seller_status' => null,
                ],
            ], 200),
            'https://api.mercadolibre.com/users/777/brands' => Http::response([], 404),
        ]);

        $this->artisan('connections:enrich-mercadolibre-profiles')
            ->assertSuccessful();

        $connection->refresh();
        $this->assertSame('SIN_TIENDA', $connection->display_name);
        $this->assertSame('5_green', $connection->reputation_level);
        $this->assertNull($connection->power_seller_status);
        $this->assertSame(
            'https://http2.mlstatic.com/storage/logos-api-admin/abc123logo-logo-esp.jpg',
            $connection->avatar_url,
        );
    }

    #[Test]
    public function oauth_profile_fetch_failure_does_not_block_activation(): void
    {
        config([
            'connectors.mercadolibre.client_id' => 'test-client',
            'connectors.mercadolibre.client_secret' => 'test-secret',
            'connectors.mercadolibre.redirect_uri' => 'https://example.test/oauth/mercadolibre/callback',
            'connectors.mercadolibre.api_base_url' => 'https://api.mercadolibre.com',
        ]);

        Http::fake([
            'https://api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'access-123',
                'refresh_token' => 'refresh-123',
                'expires_in' => 3600,
                'user_id' => '555',
            ], 200),
            'https://api.mercadolibre.com/users/me' => Http::response(['message' => 'boom'], 500),
        ]);

        $workspace = Workspace::factory()->create();
        $state = encrypt([
            'workspace_id' => $workspace->id,
            'user_id' => 1,
            'nonce' => 'abc',
        ]);

        $this->get(route('oauth.mercadolibre.callback', [
            'code' => 'auth-code',
            'state' => $state,
        ]))->assertRedirect();

        $this->assertDatabaseHas('connections', [
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '555',
            'status' => 'active',
            'display_name' => null,
        ]);
    }
}
