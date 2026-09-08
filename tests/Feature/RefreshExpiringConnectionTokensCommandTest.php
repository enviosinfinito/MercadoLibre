<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\EncryptedCredential;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RefreshExpiringConnectionTokensCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'connectors.mercadolibre.api_base_url' => 'https://api.mercadolibre.com',
            'connectors.mercadolibre.client_id' => 'test-client',
            'connectors.mercadolibre.client_secret' => 'test-secret',
        ]);
    }

    private function makeConnection(array $tokens, array $attrs = []): Connection
    {
        $workspace = Workspace::factory()->create();
        $connection = Connection::factory()->create(array_merge([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => (string) fake()->unique()->numerify('########'),
            'status' => 'active',
            'token_generation' => 1,
            'needs_reauthorization' => false,
        ], $attrs));

        $credential = new EncryptedCredential(['connection_id' => $connection->id]);
        $credential->setPlainPayload($tokens);
        $credential->save();

        return $connection->fresh(['credential']);
    }

    #[Test]
    public function refreshes_only_connections_near_expiry(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'refreshed-access',
                'refresh_token' => 'refreshed-refresh',
                'expires_in' => 21600,
            ], 200),
        ]);

        $expiring = $this->makeConnection([
            'access_token' => 'soon-dead',
            'refresh_token' => 'refresh-a',
            'expires_at' => now()->addMinutes(20)->toIso8601String(),
        ]);

        $healthy = $this->makeConnection([
            'access_token' => 'still-good',
            'refresh_token' => 'refresh-b',
            'expires_at' => now()->addHours(3)->toIso8601String(),
        ]);

        $this->artisan('connections:refresh-expiring-tokens')
            ->assertSuccessful();

        $this->assertSame(2, (int) $expiring->fresh()->token_generation);
        $this->assertSame('refreshed-access', $expiring->fresh()->credential?->plainPayload()['access_token'] ?? null);

        $this->assertSame(1, (int) $healthy->fresh()->token_generation);
        $this->assertSame('still-good', $healthy->fresh()->credential?->plainPayload()['access_token'] ?? null);
    }
}
