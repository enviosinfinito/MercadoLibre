<?php

namespace Tests\Feature;

use App\Jobs\FetchExternalResourceJob;
use App\Jobs\ProcessCanonicalOrderJob;
use App\Models\Connection;
use App\Models\ConnectionSyncProfile;
use App\Models\EncryptedCredential;
use App\Models\Order;
use App\Models\RawResourceSnapshot;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FetchExternalResourceJobTokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function fetch_refreshes_expired_token_then_stores_order_snapshot(): void
    {
        Queue::fake([ProcessCanonicalOrderJob::class]);

        config([
            'connectors.mercadolibre.api_base_url' => 'https://api.mercadolibre.com',
            'connectors.mercadolibre.client_id' => 'test-client',
            'connectors.mercadolibre.client_secret' => 'test-secret',
        ]);

        $workspace = Workspace::factory()->create();
        $connection = Connection::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '112184176',
            'status' => 'active',
            'token_generation' => 1,
        ]);

        $credential = new EncryptedCredential(['connection_id' => $connection->id]);
        $credential->setPlainPayload([
            'access_token' => 'expired-access',
            'refresh_token' => 'live-refresh',
            'expires_in' => 3600,
            'expires_at' => now()->subHour()->toIso8601String(),
            'user_id' => '112184176',
        ]);
        $credential->save();

        ConnectionSyncProfile::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'resource_key' => 'orders',
            'enabled' => true,
            'config' => [
                'include' => [
                    'raw_snapshot' => true,
                    'lines' => true,
                    'buyer' => true,
                ],
            ],
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'fresh-access',
                'refresh_token' => 'fresh-refresh',
                'expires_in' => 21600,
            ], 200),
            'https://api.mercadolibre.com/orders/2000017835831536' => Http::response([
                'id' => 2000017835831536,
                'status' => 'paid',
                'currency_id' => 'MXN',
                'total_amount' => 100,
                'date_created' => '2026-08-08T21:38:44.000-04:00',
                'date_closed' => '2026-08-08T21:38:47.000-04:00',
                'buyer' => ['id' => 1],
                'order_items' => [],
            ], 200),
        ]);

        (new FetchExternalResourceJob(
            $workspace->id,
            $connection->id,
            'order',
            '2000017835831536',
        ))->handle();

        $this->assertDatabaseHas('raw_resource_snapshots', [
            'connection_id' => $connection->id,
            'resource_type' => 'order',
            'external_id' => '2000017835831536',
        ]);

        $this->assertSame(2, (int) $connection->fresh()->token_generation);
        $this->assertSame(
            'fresh-access',
            $connection->fresh()->credential?->plainPayload()['access_token'] ?? null,
        );

        Queue::assertPushed(ProcessCanonicalOrderJob::class);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(1, RawResourceSnapshot::query()->count());
    }
}
