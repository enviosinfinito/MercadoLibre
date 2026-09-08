<?php

namespace Tests\Feature;

use App\Domain\Integrations\Actions\EnsureFreshConnectionToken;
use App\Models\Connection;
use App\Models\EncryptedCredential;
use App\Models\TokenRefreshAttempt;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class EnsureFreshConnectionTokenTest extends TestCase
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

    /**
     * @return array{0: Connection, 1: EncryptedCredential}
     */
    private function connectionWithTokens(array $tokens): array
    {
        $workspace = Workspace::factory()->create();
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '111',
            'status' => 'active',
            'token_generation' => 1,
            'needs_reauthorization' => false,
        ]);

        $credential = new EncryptedCredential(['connection_id' => $connection->id]);
        $credential->setPlainPayload($tokens);
        $credential->save();

        return [$connection->fresh(['credential']), $credential];
    }

    #[Test]
    public function valid_token_is_returned_without_refresh(): void
    {
        Http::preventStrayRequests();

        [$connection] = $this->connectionWithTokens([
            'access_token' => 'still-valid',
            'refresh_token' => 'refresh-1',
            'expires_in' => 3600,
            'expires_at' => now()->addHour()->toIso8601String(),
        ]);

        $token = app(EnsureFreshConnectionToken::class)->execute($connection);

        $this->assertSame('still-valid', $token);
        $this->assertSame(1, (int) $connection->fresh()->token_generation);
        $this->assertSame(0, TokenRefreshAttempt::query()->count());
    }

    #[Test]
    public function expired_token_is_refreshed_and_recorded(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'expires_in' => 21600,
            ], 200),
        ]);

        [$connection] = $this->connectionWithTokens([
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'expires_in' => 3600,
            'expires_at' => now()->subMinute()->toIso8601String(),
        ]);

        $token = app(EnsureFreshConnectionToken::class)->execute($connection);

        $this->assertSame('new-access', $token);
        $connection->refresh();
        $this->assertSame(2, (int) $connection->token_generation);
        $this->assertFalse($connection->needs_reauthorization);

        $payload = $connection->credential?->plainPayload() ?? [];
        $this->assertSame('new-access', $payload['access_token'] ?? null);
        $this->assertSame('new-refresh', $payload['refresh_token'] ?? null);

        $this->assertDatabaseHas('token_refresh_attempts', [
            'connection_id' => $connection->id,
            'status' => 'success',
        ]);
    }

    #[Test]
    public function missing_refresh_token_marks_needs_reauthorization(): void
    {
        Http::preventStrayRequests();

        [$connection] = $this->connectionWithTokens([
            'access_token' => 'old-access',
            'expires_at' => now()->subMinute()->toIso8601String(),
        ]);

        try {
            app(EnsureFreshConnectionToken::class)->execute($connection);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('refresh_token', $e->getMessage());
        }

        $connection->refresh();
        $this->assertTrue($connection->needs_reauthorization);
        $this->assertDatabaseHas('token_refresh_attempts', [
            'connection_id' => $connection->id,
            'status' => 'failed',
        ]);
    }
}
