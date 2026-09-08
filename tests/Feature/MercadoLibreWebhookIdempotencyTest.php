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

class MercadoLibreWebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function duplicate_webhooks_do_not_create_duplicate_raw_events(): void
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $connection = Connection::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '12345',
            'status' => 'active',
        ]);

        config()->set('connectors.mercadolibre.client_id', '859263633012393');

        $payload = [
            'resource' => '/orders/999',
            'user_id' => 12345,
            'topic' => 'orders_v2',
            'application_id' => 859263633012393,
            'attempts' => 1,
            'sent' => now()->toIso8601String(),
            '_id' => 'evt-unique-1',
        ];

        $this->postJson('/webhooks/mercadolibre', $payload)->assertOk();
        $this->postJson('/webhooks/mercadolibre', $payload)->assertOk();

        $this->assertSame(1, RawWebhookEvent::query()->count());
        Queue::assertPushed(ProcessWebhookIngressJob::class, 1);

        $event = RawWebhookEvent::query()->first();
        $this->assertSame($connection->id, $event->connection_id);
        $this->assertSame('999', $event->external_resource_id);
    }
}
