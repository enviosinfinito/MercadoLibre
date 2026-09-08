<?php

namespace Tests\Feature;

use App\Jobs\ProcessWebhookIngressJob;
use App\Models\Connection;
use App\Models\RawWebhookEvent;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MercadoLibreWebhookAuthenticityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function rejects_webhook_with_foreign_application_id(): void
    {
        Queue::fake();
        config()->set('connectors.mercadolibre.client_id', '859263633012393');
        config()->set('connectors.mercadolibre.webhook_secret', '');

        $this->postJson('/webhooks/mercadolibre', [
            'resource' => '/orders/1',
            'user_id' => 12345,
            'topic' => 'orders_v2',
            'application_id' => 999,
            '_id' => 'evt-foreign-app',
        ])->assertUnauthorized();

        $this->assertSame(0, RawWebhookEvent::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function rejects_webhook_when_shared_secret_required_but_missing(): void
    {
        Queue::fake();
        config()->set('connectors.mercadolibre.client_id', '859263633012393');
        config()->set('connectors.mercadolibre.webhook_secret', 'super-secret');

        Workspace::factory()->create();

        $this->postJson('/webhooks/mercadolibre', [
            'resource' => '/orders/1',
            'user_id' => 12345,
            'topic' => 'orders_v2',
            'application_id' => 859263633012393,
            '_id' => 'evt-no-secret',
        ])->assertUnauthorized();

        $this->assertSame(0, RawWebhookEvent::query()->count());
    }

    #[Test]
    public function accepts_webhook_with_matching_application_id_and_secret_query(): void
    {
        Queue::fake();
        config()->set('connectors.mercadolibre.client_id', '859263633012393');
        config()->set('connectors.mercadolibre.webhook_secret', 'super-secret');

        $workspace = Workspace::factory()->create();
        Connection::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '12345',
            'status' => 'active',
        ]);

        $this->postJson('/webhooks/mercadolibre?secret=super-secret', [
            'resource' => '/orders/42',
            'user_id' => 12345,
            'topic' => 'orders_v2',
            'application_id' => 859263633012393,
            '_id' => 'evt-with-secret',
        ])->assertOk();

        $this->assertSame(1, RawWebhookEvent::query()->count());
        Queue::assertPushed(ProcessWebhookIngressJob::class, 1);
    }

    #[Test]
    public function accepts_webhook_with_valid_x_signature_hmac(): void
    {
        Queue::fake();
        config()->set('connectors.mercadolibre.client_id', '859263633012393');
        config()->set('connectors.mercadolibre.webhook_secret', 'hmac-secret');

        $workspace = Workspace::factory()->create();
        Connection::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '12345',
            'status' => 'active',
        ]);

        $ts = '1704908010';
        $requestId = 'req-abc';
        $dataId = '42';
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $v1 = hash_hmac('sha256', $manifest, 'hmac-secret');

        $this->withHeaders([
            'x-signature' => "ts={$ts},v1={$v1}",
            'x-request-id' => $requestId,
        ])->postJson('/webhooks/mercadolibre?data.id=42', [
            'resource' => '/orders/42',
            'user_id' => 12345,
            'topic' => 'orders_v2',
            'application_id' => 859263633012393,
            '_id' => 'evt-hmac',
        ])->assertOk();

        Queue::assertPushed(ProcessWebhookIngressJob::class, 1);
    }
}
