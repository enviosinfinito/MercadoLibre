<?php

namespace Tests\Feature;

use App\Domain\PostSale\Actions\UpsertCanonicalClaim;
use App\Integrations\Contracts\Dto\PullRequest;
use App\Integrations\Contracts\Dto\WebhookPayload;
use App\Integrations\MercadoLibre\Connector\MercadoLibreConnector;
use App\Jobs\FetchExternalResourceJob;
use App\Jobs\ProcessCanonicalClaimJob;
use App\Models\Claim;
use App\Models\Connection;
use App\Models\ConnectionSyncProfile;
use App\Models\EncryptedCredential;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\RawResourceSnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MercadoLibreClaimsSyncTest extends TestCase
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
        $workspace = Workspace::factory()->create();

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
            'resource_key' => 'claims',
            'enabled' => true,
            'config' => ['include' => ['raw_snapshot' => true]],
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$user, $workspace, $connection];
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleClaimPayload(
        string $claimId = '5033102536',
        string $resource = 'order',
        string $resourceId = '2001001',
        string $status = 'opened',
    ): array {
        return [
            'id' => (int) $claimId,
            'resource_id' => (int) $resourceId,
            'status' => $status,
            'type' => 'mediations',
            'stage' => 'claim',
            'resource' => $resource,
            'reason_id' => 'PDD316',
            'site_id' => 'MLM',
            'date_created' => '2026-08-01T12:00:00.000-00:00',
            'last_updated' => '2026-08-02T12:00:00.000-00:00',
            'players' => [
                ['role' => 'complainant', 'type' => 'buyer', 'user_id' => 555001],
                ['role' => 'respondent', 'type' => 'seller', 'user_id' => 112184176],
            ],
            'resolution' => $status === 'closed' ? [
                'reason' => 'payment_refunded',
                'date_created' => '2026-08-03T12:00:00.000-00:00',
                'closed_by' => 'mediator',
            ] : null,
        ];
    }

    private function createOrder(Workspace $workspace, Connection $connection, string $externalOrderId = '2001001'): Order
    {
        return Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => $externalOrderId,
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => 100,
            'ordered_at' => now(),
        ]);
    }

    #[Test]
    public function parse_webhook_extracts_claim_resource(): void
    {
        $connector = app(MercadoLibreConnector::class);

        $parsed = $connector->parseWebhook(new WebhookPayload(
            body: [
                'topic' => 'claims',
                'resource' => '/post-purchase/v1/claims/5033102536',
                'user_id' => 112184176,
                '_id' => 'evt-c-1',
            ],
        ));

        $this->assertSame('claim', $parsed->type);
        $this->assertCount(1, $parsed->events);
        $this->assertSame('claim', $parsed->events[0]['resource_type']);
        $this->assertSame('5033102536', $parsed->events[0]['external_id']);
    }

    #[Test]
    public function pull_claims_returns_paginated_items(): void
    {
        Http::fake([
            '*/post-purchase/v1/claims/search*' => Http::response([
                'paging' => ['offset' => 0, 'limit' => 30, 'total' => 1],
                'data' => [
                    $this->sampleClaimPayload(),
                ],
            ], 200),
        ]);

        $connector = app(MercadoLibreConnector::class);
        $result = $connector->pull(new PullRequest(
            resource: 'claims',
            cursor: ['offset' => 0],
            options: [
                'access_token' => 'token',
                'user_id' => '112184176',
            ],
        ));

        $this->assertCount(1, $result->items);
        $this->assertSame(5033102536, $result->items[0]['id']);
        // After opened phase completes, cursor advances to closed.
        $this->assertFalse((bool) ($result->nextCursor['done'] ?? true));
        $this->assertSame('closed', $result->nextCursor['status'] ?? null);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/post-purchase/v1/claims/search')
                && str_contains($request->url(), 'players.user_id=112184176')
                && str_contains($request->url(), 'players.role=respondent')
                && str_contains($request->url(), 'status=opened');
        });
    }

    #[Test]
    public function process_canonical_claim_links_order_resource(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();
        $order = $this->createOrder($workspace, $connection);

        $snapshot = RawResourceSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'resource_type' => 'claim',
            'external_id' => '5033102536',
            'payload' => $this->sampleClaimPayload(),
            'checksum' => 'c-abc',
            'fetched_at' => now(),
        ]);

        (new ProcessCanonicalClaimJob(
            $workspace->id,
            $connection->id,
            (int) $snapshot->id,
        ))->handle();

        $claim = Claim::query()
            ->where('connection_id', $connection->id)
            ->where('external_claim_id', '5033102536')
            ->firstOrFail();

        $this->assertSame('opened', $claim->status);
        $this->assertSame('mediations', $claim->type);
        $this->assertSame('claim', $claim->stage);
        $this->assertSame('PDD316', $claim->reason_id);
        $this->assertSame($order->id, $claim->order_id);
        $this->assertSame($snapshot->id, $claim->raw_snapshot_id);
        $this->assertNotNull($claim->opened_at);
    }

    #[Test]
    public function process_canonical_claim_links_shipment_resource(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();
        $order = $this->createOrder($workspace, $connection, '2002002');

        Shipment::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'external_shipment_id' => '7138572864',
            'status' => 'shipped',
        ]);

        $mapped = app(UpsertCanonicalClaim::class)->mapFromProviderPayload(
            $this->sampleClaimPayload('111', 'shipment', '7138572864', 'closed'),
        );

        $claim = app(UpsertCanonicalClaim::class)->execute($workspace->id, $connection->id, $mapped);

        $this->assertSame($order->id, $claim->order_id);
        $this->assertSame('closed', $claim->status);
        $this->assertNotNull($claim->closed_at);
        $this->assertSame('shipment', $claim->resource);
        $this->assertSame('payment_refunded', $claim->meta['resolution']['reason'] ?? null);
        $this->assertSame('payment_refunded', $claim->resolutionReason());
    }

    #[Test]
    public function fetch_external_resource_projects_claim(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();
        $order = $this->createOrder($workspace, $connection);

        Http::fake([
            '*/post-purchase/v1/claims/900099*' => Http::response(
                $this->sampleClaimPayload('900099', 'order', $order->external_order_id),
                200,
            ),
        ]);

        (new FetchExternalResourceJob(
            $workspace->id,
            $connection->id,
            'claim',
            '900099',
            projectSynchronously: true,
        ))->handle();

        $claim = Claim::query()
            ->where('connection_id', $connection->id)
            ->where('external_claim_id', '900099')
            ->firstOrFail();

        $this->assertSame($order->id, $claim->order_id);
        $this->assertSame('opened', $claim->status);
    }

    #[Test]
    public function claims_index_and_order_show_include_claims(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();
        $order = $this->createOrder($workspace, $connection);

        app(UpsertCanonicalClaim::class)->execute($workspace->id, $connection->id, [
            'external_claim_id' => '5033102536',
            'order_id' => $order->id,
            'type' => 'mediations',
            'stage' => 'claim',
            'status' => 'opened',
            'reason' => 'PDD316',
            'reason_id' => 'PDD316',
            'resource' => 'order',
            'resource_external_id' => $order->external_order_id,
            'opened_at' => now(),
        ]);

        $index = $this->get(route('claims.index'));
        $index->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Claims/Index')
                ->has('claims.data', 1)
                ->where('claims.data.0.external_claim_id', '5033102536')
                ->where('claims.data.0.order_id', $order->id)
                ->where('claims.data.0.resolution_reason', null)
            );

        $show = $this->getJson(route('orders.show', $order));
        $show->assertOk()
            ->assertJsonPath('claim_stats.total_count', 1)
            ->assertJsonPath('claim_stats.opened_count', 1)
            ->assertJsonPath('claims.0.external_claim_id', '5033102536')
            ->assertJsonPath('claims.0.resolution_reason', null);
    }

    #[Test]
    public function order_show_exposes_resolution_reason_for_closed_return(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();
        $order = $this->createOrder($workspace, $connection, '2000016383039434');

        $mapped = app(UpsertCanonicalClaim::class)->mapFromProviderPayload([
            'id' => 5511709764,
            'resource_id' => (int) $order->external_order_id,
            'status' => 'closed',
            'type' => 'mediations',
            'stage' => 'dispute',
            'resource' => 'order',
            'reason_id' => 'PDD9939',
            'date_created' => '2026-05-13T12:00:00.000-00:00',
            'last_updated' => '2026-05-25T18:36:22.000-00:00',
            'players' => [],
            'resolution' => [
                'reason' => 'item_returned',
                'benefited' => ['complainant'],
                'closed_by' => 'mediator',
                'date_created' => '2026-05-25T14:36:22.000-04:00',
                'applied_coverage' => true,
            ],
        ]);

        app(UpsertCanonicalClaim::class)->execute($workspace->id, $connection->id, [
            ...$mapped,
            'order_id' => $order->id,
        ]);

        $index = $this->get(route('claims.index'));
        $index->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Claims/Index')
                ->where('claims.data.0.external_claim_id', '5511709764')
                ->where('claims.data.0.resolution_reason', 'item_returned')
            );

        $order->refresh();
        $this->assertSame('returned', $order->post_sale_outcome);

        $show = $this->getJson(route('orders.show', $order));
        $show->assertOk()
            ->assertJsonPath('order.post_sale_outcome', 'returned')
            ->assertJsonPath('claims.0.external_claim_id', '5511709764')
            ->assertJsonPath('claims.0.status', 'closed')
            ->assertJsonPath('claims.0.stage', 'dispute')
            ->assertJsonPath('claims.0.resolution_reason', 'item_returned');
    }
}
