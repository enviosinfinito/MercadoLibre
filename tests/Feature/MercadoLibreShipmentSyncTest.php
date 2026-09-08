<?php

namespace Tests\Feature;

use App\Domain\Fulfillment\Actions\UpsertCanonicalShipment;
use App\Domain\Sales\Actions\UpsertCanonicalOrder;
use App\Integrations\Contracts\Dto\WebhookPayload;
use App\Integrations\MercadoLibre\Connector\MercadoLibreConnector;
use App\Jobs\FetchExternalResourceJob;
use App\Jobs\ProcessCanonicalOrderJob;
use App\Jobs\ProcessCanonicalShipmentJob;
use App\Models\Connection;
use App\Models\ConnectionSyncProfile;
use App\Models\EncryptedCredential;
use App\Models\Order;
use App\Models\RawResourceSnapshot;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MercadoLibreShipmentSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Connection}
     */
    private function actingMemberWithMeliConnection(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['outbound_dry_run' => true]);

        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        $connection = Connection::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '112184176',
            'status' => 'active',
            'token_generation' => 1,
        ]);

        $credential = new EncryptedCredential(['connection_id' => $connection->id]);
        $credential->setPlainPayload([
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh',
            'user_id' => '112184176',
        ]);
        $credential->save();

        ConnectionSyncProfile::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'resource_key' => 'orders',
            'enabled' => true,
            'config' => ['include' => ['raw_snapshot' => true, 'lines' => true, 'buyer' => true]],
        ]);

        ConnectionSyncProfile::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'resource_key' => 'shipments',
            'enabled' => true,
            'config' => ['include' => ['raw_snapshot' => true]],
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$user, $workspace, $connection];
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleShipmentPayload(
        string $shipmentId = '400001',
        string $orderId = '200000123',
        string $status = 'delivered',
    ): array {
        return [
            'id' => (int) $shipmentId,
            'order_id' => (int) $orderId,
            'status' => $status,
            'tracking_number' => 'TRACK-123',
            'tracking_method' => 'Estafeta',
            'status_history' => [
                'date_shipped' => '2026-08-04T10:00:00.000-00:00',
                'date_delivered' => '2026-08-05T15:30:00.000-00:00',
            ],
        ];
    }

    #[Test]
    public function parse_webhook_extracts_shipment_resource(): void
    {
        $connector = app(MercadoLibreConnector::class);

        $parsed = $connector->parseWebhook(new WebhookPayload(
            body: [
                'topic' => 'shipments',
                'resource' => '/shipments/400001',
                'user_id' => 112184176,
                '_id' => 'evt-ship-1',
            ],
        ));

        $this->assertSame('shipment', $parsed->type);
        $this->assertCount(1, $parsed->events);
        $this->assertSame('shipment', $parsed->events[0]['resource_type']);
        $this->assertSame('400001', $parsed->events[0]['external_id']);
    }

    #[Test]
    public function process_canonical_shipment_upserts_and_mirrors_order_status(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();

        $order = app(UpsertCanonicalOrder::class)->execute($workspace->id, $connection->id, [
            'external_order_id' => '200000123',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '100',
            'meta' => ['shipping' => ['id' => 400001]],
            'lines' => [],
        ]);

        $snapshot = RawResourceSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'resource_type' => 'shipment',
            'external_id' => '400001',
            'payload' => $this->sampleShipmentPayload(),
            'checksum' => 'ship-abc',
            'fetched_at' => now(),
        ]);

        (new ProcessCanonicalShipmentJob(
            $workspace->id,
            $connection->id,
            (int) $snapshot->id,
        ))->handle();

        $shipment = Shipment::query()
            ->where('connection_id', $connection->id)
            ->where('external_shipment_id', '400001')
            ->firstOrFail();

        $this->assertSame('delivered', $shipment->status);
        $this->assertSame($order->id, $shipment->order_id);
        $this->assertSame('TRACK-123', $shipment->tracking_number);
        $this->assertSame('Estafeta', $shipment->carrier);
        $this->assertNotNull($shipment->shipped_at);
        $this->assertNotNull($shipment->delivered_at);

        $order->refresh();
        $this->assertSame('delivered', $order->status);
    }

    #[Test]
    public function process_canonical_order_dispatches_shipment_fetch_when_shipping_id_present(): void
    {
        Queue::fake();

        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();

        $snapshot = RawResourceSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'resource_type' => 'order',
            'external_id' => '200000555',
            'payload' => [
                'id' => '200000555',
                'status' => 'paid',
                'currency_id' => 'MXN',
                'total_amount' => 50,
                'date_created' => '2026-08-03T03:17:00.000-00:00',
                'date_closed' => '2026-08-03T03:17:00.000-00:00',
                'shipping' => ['id' => 400099],
                'order_items' => [],
            ],
            'checksum' => 'ord-ship',
            'fetched_at' => now(),
        ]);

        (new ProcessCanonicalOrderJob(
            $workspace->id,
            $connection->id,
            (int) $snapshot->id,
            applyInventoryEffects: false,
        ))->handle();

        $order = Order::query()->where('external_order_id', '200000555')->firstOrFail();
        $this->assertSame('400099', (string) ($order->meta['shipping']['id'] ?? null));

        Queue::assertPushed(FetchExternalResourceJob::class, function (FetchExternalResourceJob $job) use ($connection) {
            return $job->connectionId === $connection->id
                && $job->resourceType === 'shipment'
                && $job->externalId === '400099';
        });
    }

    #[Test]
    public function fetch_external_resource_projects_shipment(): void
    {
        Queue::fake([ProcessCanonicalShipmentJob::class]);

        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();

        Http::preventStrayRequests();
        Http::fake([
            '*/shipments/400001*' => Http::response($this->sampleShipmentPayload(), 200),
        ]);

        (new FetchExternalResourceJob(
            $workspace->id,
            $connection->id,
            'shipment',
            '400001',
        ))->handle();

        $this->assertDatabaseHas('raw_resource_snapshots', [
            'connection_id' => $connection->id,
            'resource_type' => 'shipment',
            'external_id' => '400001',
        ]);

        Queue::assertPushed(ProcessCanonicalShipmentJob::class);
    }

    #[Test]
    public function order_show_timeline_marks_shipped_and_delivered_when_shipment_exists(): void
    {
        [$user, $workspace, $connection] = $this->actingMemberWithMeliConnection();

        $order = app(UpsertCanonicalOrder::class)->execute($workspace->id, $connection->id, [
            'external_order_id' => '2000017726922752',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '200',
            'ordered_at' => now()->subDays(4),
            'paid_at' => now()->subDays(4),
            'lines' => [],
        ]);

        app(UpsertCanonicalShipment::class)->execute($workspace->id, $connection->id, [
            'external_shipment_id' => '400001',
            'status' => 'delivered',
            'order_id' => $order->id,
            'shipped_at' => now()->subDays(2),
            'delivered_at' => now()->subDay(),
            'carrier' => 'Estafeta',
            'tracking_number' => 'TRK-1',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['workspace_id' => $workspace->id])
            ->getJson(route('orders.show', $order));

        $response->assertOk();
        $timeline = collect($response->json('timeline'))->keyBy('key');

        $this->assertTrue($timeline['paid']['done']);
        $this->assertTrue($timeline['shipped']['done']);
        $this->assertTrue($timeline['delivered']['done']);
        $this->assertSame('delivered', $response->json('shipment.status'));
    }

    #[Test]
    public function upsert_order_does_not_downgrade_delivered_status(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();

        app(UpsertCanonicalOrder::class)->execute($workspace->id, $connection->id, [
            'external_order_id' => '200000777',
            'status' => 'delivered',
            'currency_code' => 'MXN',
            'total_amount' => '10',
            'lines' => [],
        ]);

        $order = app(UpsertCanonicalOrder::class)->execute($workspace->id, $connection->id, [
            'external_order_id' => '200000777',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '10',
            'lines' => [],
        ]);

        $this->assertSame('delivered', $order->status);
    }

    #[Test]
    public function shared_pack_shipment_mirrors_status_to_all_sibling_orders(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();

        $orderA = app(UpsertCanonicalOrder::class)->execute($workspace->id, $connection->id, [
            'external_order_id' => '200000001',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '100',
            'meta' => ['shipping' => ['id' => 555001]],
            'lines' => [],
        ]);

        $orderB = app(UpsertCanonicalOrder::class)->execute($workspace->id, $connection->id, [
            'external_order_id' => '200000002',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '50',
            'meta' => ['shipping' => ['id' => 555001]],
            'lines' => [],
        ]);

        app(UpsertCanonicalShipment::class)->execute($workspace->id, $connection->id, [
            'external_shipment_id' => '555001',
            'external_order_id' => '200000001',
            'status' => 'delivered',
            'shipped_at' => now()->subDay(),
            'delivered_at' => now(),
            'carrier' => 'MEL Distribution',
            'tracking_number' => 'PACK-TRACK',
        ]);

        $this->assertSame('delivered', $orderA->fresh()->status);
        $this->assertSame('delivered', $orderB->fresh()->status);

        $shipment = Shipment::query()
            ->where('connection_id', $connection->id)
            ->where('external_shipment_id', '555001')
            ->firstOrFail();

        $this->assertSame($orderA->id, $shipment->order_id);

        $this->getJson(route('orders.show', $orderB))
            ->assertOk()
            ->assertJsonPath('shipment.id', $shipment->id)
            ->assertJsonPath('shipment.status', 'delivered')
            ->assertJsonPath('order.status', 'delivered');
    }

    #[Test]
    public function backfill_command_queues_shipment_fetches(): void
    {
        Queue::fake();

        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();

        app(UpsertCanonicalOrder::class)->execute($workspace->id, $connection->id, [
            'external_order_id' => '200000888',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '10',
            'meta' => ['shipping' => ['id' => 411111]],
            'lines' => [],
        ]);

        $this->artisan('sync:backfill-meli-shipments', [
            '--connection' => $connection->id,
        ])->assertSuccessful();

        Queue::assertPushed(FetchExternalResourceJob::class, function (FetchExternalResourceJob $job) use ($connection) {
            return $job->connectionId === $connection->id
                && $job->resourceType === 'shipment'
                && $job->externalId === '411111';
        });
    }
}
