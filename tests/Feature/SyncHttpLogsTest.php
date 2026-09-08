<?php

namespace Tests\Feature;

use App\Domain\Platform\DiagnosticHttpLogging;
use App\Jobs\BootstrapMercadoLibreListingsJob;
use App\Models\Connection;
use App\Models\ConnectionSyncProfile;
use App\Models\EncryptedCredential;
use App\Models\SyncHttpLog;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SyncHttpLogsTest extends TestCase
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

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$user, $workspace, $connection];
    }

    #[Test]
    public function bootstrap_records_outbound_http_logs_with_request_and_response(): void
    {
        app(DiagnosticHttpLogging::class)->enable(10);

        [, , $connection] = $this->actingMemberWithMeliConnection();

        ConnectionSyncProfile::query()->create([
            'workspace_id' => $connection->workspace_id,
            'connection_id' => $connection->id,
            'resource_key' => 'listings',
            'enabled' => true,
            'config' => [
                'include' => [
                    'raw_snapshot' => true,
                    'price' => true,
                    'stock' => true,
                    'pictures' => false,
                    'description' => false,
                    'attributes' => false,
                ],
            ],
        ]);

        Http::preventStrayRequests();
        Http::fake([
            '*/users/*/items/search*' => Http::response([
                'results' => ['MLM123'],
                'paging' => ['total' => 1, 'offset' => 0, 'limit' => 50],
            ], 200),
            '*/items*' => Http::response([
                [
                    'code' => 200,
                    'body' => [
                        'id' => 'MLM123',
                        'title' => 'Demo',
                        'status' => 'active',
                        'price' => 10,
                        'currency_id' => 'MXN',
                        'available_quantity' => 1,
                        'seller_custom_field' => 'SKU-1',
                        'variations' => [],
                    ],
                ],
            ], 200),
        ]);

        (new BootstrapMercadoLibreListingsJob(
            (int) $connection->workspace_id,
            (int) $connection->id,
        ))->handle();

        $logs = SyncHttpLog::query()
            ->where('connection_id', $connection->id)
            ->where('direction', 'out')
            ->orderBy('id')
            ->get();

        $this->assertGreaterThanOrEqual(2, $logs->count());

        $search = $logs->firstWhere('endpoint_group', 'listings.search');
        $this->assertNotNull($search);
        $this->assertSame('GET', $search->method);
        $this->assertSame(200, $search->response_status);
        $this->assertNotNull($search->response_body_redacted);
        $this->assertStringContainsString('MLM123', (string) $search->response_body_redacted);
        $this->assertNotNull($search->sync_run_id);
        $this->assertNotNull($search->correlation_id);
    }

    #[Test]
    public function index_and_show_expose_request_and_response(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();

        $log = SyncHttpLog::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'direction' => 'out',
            'correlation_id' => 'corr-test-1',
            'method' => 'GET',
            'url' => 'https://api.mercadolibre.com/orders/1',
            'endpoint_group' => 'orders',
            'response_status' => 200,
            'latency_ms' => 12,
            'request_body_redacted' => '{"ping":true}',
            'response_body_redacted' => '{"id":1,"status":"paid"}',
        ]);

        $this->get(route('sync-logs.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SyncHttpLogs/Index')
                ->has('logs.data', 1)
                ->where('logs.data.0.id', $log->id));

        $this->getJson(route('sync-logs.show', $log))
            ->assertOk()
            ->assertJsonPath('log.id', $log->id)
            ->assertJsonPath('log.request_body_redacted', '{"ping":true}')
            ->assertJsonPath('log.response_body_redacted', '{"id":1,"status":"paid"}');
    }

    #[Test]
    public function webhook_records_inbound_http_log(): void
    {
        app(DiagnosticHttpLogging::class)->enable(10);

        \Illuminate\Support\Facades\Queue::fake();

        [, , $connection] = $this->actingMemberWithMeliConnection();

        config()->set('connectors.mercadolibre.client_id', '859263633012393');
        config()->set('connectors.mercadolibre.webhook_secret', '');

        $payload = [
            'resource' => '/orders/999',
            'user_id' => (int) $connection->external_user_id,
            'topic' => 'orders_v2',
            'application_id' => 859263633012393,
            'attempts' => 1,
            'sent' => now()->toIso8601String(),
            '_id' => 'evt-http-log-1',
        ];

        $this->postJson('/webhooks/mercadolibre', $payload)->assertOk();

        $this->assertDatabaseHas('sync_http_logs', [
            'connection_id' => $connection->id,
            'direction' => 'in',
            'endpoint_group' => 'webhooks.orders_v2',
            'response_status' => 200,
        ]);
    }

    #[Test]
    public function index_returns_json_paginator_without_inertia_header(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();

        foreach (range(1, 30) as $i) {
            SyncHttpLog::query()->create([
                'workspace_id' => $workspace->id,
                'connection_id' => $connection->id,
                'provider' => 'mercadolibre',
                'direction' => $i % 2 === 0 ? 'out' : 'in',
                'correlation_id' => 'corr-'.$i,
                'method' => 'GET',
                'url' => 'https://api.mercadolibre.com/orders/'.$i,
                'endpoint_group' => 'orders',
                'response_status' => 200,
                'latency_ms' => 10 + $i,
            ]);
        }

        $this->getJson(route('sync-logs.index', ['page' => 2]))
            ->assertOk()
            ->assertJsonPath('current_page', 2)
            ->assertJsonStructure(['data', 'current_page', 'last_page', 'total', 'next_page_url']);
    }

    #[Test]
    public function index_filters_by_http_direction_and_orphan(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();

        $outbound = SyncHttpLog::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'direction' => 'out',
            'sync_run_id' => null,
            'correlation_id' => 'corr-out',
            'method' => 'GET',
            'url' => 'https://api.mercadolibre.com/orders/1',
            'endpoint_group' => 'orders',
            'response_status' => 200,
            'latency_ms' => 12,
        ]);

        SyncHttpLog::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'direction' => 'in',
            'sync_run_id' => null,
            'correlation_id' => 'corr-in',
            'method' => 'POST',
            'url' => 'https://example.test/webhooks/mercadolibre',
            'endpoint_group' => 'webhooks.orders_v2',
            'response_status' => 200,
            'latency_ms' => 5,
        ]);

        $this->get(route('sync-logs.index', ['http_direction' => 'out']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SyncHttpLogs/Index')
                ->has('logs.data', 1)
                ->where('logs.data.0.id', $outbound->id)
                ->where('filters.http_direction', 'out'));

        $this->get(route('sync-logs.index', ['orphan' => '1']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SyncHttpLogs/Index')
                ->has('logs.data', 2)
                ->where('filters.orphan', '1'));
    }

    #[Test]
    public function index_filters_by_date_range(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();

        $old = SyncHttpLog::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'direction' => 'out',
            'method' => 'GET',
            'url' => 'https://api.mercadolibre.com/orders/old',
            'endpoint_group' => 'orders',
            'response_status' => 200,
        ]);
        $old->forceFill([
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ])->save();

        $recent = SyncHttpLog::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'direction' => 'out',
            'method' => 'GET',
            'url' => 'https://api.mercadolibre.com/orders/recent',
            'endpoint_group' => 'orders',
            'response_status' => 200,
        ]);
        $recent->forceFill([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ])->save();

        $from = now()->subDays(2)->toDateString();
        $to = now()->toDateString();

        $this->get(route('sync-logs.index', ['from' => $from, 'to' => $to]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SyncHttpLogs/Index')
                ->has('logs.data', 1)
                ->where('logs.data.0.id', $recent->id));

        $this->assertNotSame($old->id, $recent->id);
    }

    #[Test]
    public function body_search_requires_connection_and_date_range(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();

        SyncHttpLog::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'direction' => 'out',
            'method' => 'GET',
            'url' => 'https://api.mercadolibre.com/orders/1',
            'endpoint_group' => 'orders',
            'response_status' => 200,
            'response_body_redacted' => '{"needle":"find-me-please"}',
        ]);

        $this->from(route('sync-logs.index'))
            ->get(route('sync-logs.index', ['body_search' => 'find-me']))
            ->assertRedirect()
            ->assertSessionHasErrors('body_search');

        $from = now()->subDay()->toDateString();
        $to = now()->toDateString();

        $this->get(route('sync-logs.index', [
            'body_search' => 'find-me',
            'connection_id' => $connection->id,
            'from' => $from,
            'to' => $to,
        ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SyncHttpLogs/Index')
                ->has('logs.data', 1)
                ->where('filters.body_search', 'find-me'));
    }

    #[Test]
    public function list_updates_requires_since_id(): void
    {
        $this->actingMemberWithMeliConnection();

        $this->getJson(route('sync-logs.list-updates'))
            ->assertStatus(422)
            ->assertJson(['message' => 'since_id requerido']);
    }

    #[Test]
    public function list_updates_returns_logs_newer_than_since_id_matching_filters(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();

        $old = SyncHttpLog::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'direction' => 'out',
            'method' => 'GET',
            'url' => 'https://api.mercadolibre.com/orders/old',
            'endpoint_group' => 'orders',
            'response_status' => 200,
        ]);

        $newerOut = SyncHttpLog::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'direction' => 'out',
            'method' => 'GET',
            'url' => 'https://api.mercadolibre.com/orders/new',
            'endpoint_group' => 'orders',
            'response_status' => 200,
        ]);

        SyncHttpLog::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'direction' => 'in',
            'method' => 'POST',
            'url' => 'https://example.test/webhooks/mercadolibre',
            'endpoint_group' => 'webhooks.orders_v2',
            'response_status' => 200,
        ]);

        $this->assertGreaterThan($old->id, $newerOut->id);

        $this->getJson(route('sync-logs.list-updates', [
            'since_id' => $old->id,
            'http_direction' => 'out',
        ]))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $newerOut->id)
            ->assertJsonStructure([
                'data' => [
                    [
                        'id',
                        'direction',
                        'endpoint_group',
                        'url',
                        'latency_ms',
                    ],
                ],
                'count',
            ]);
    }

    #[Test]
    public function list_updates_returns_empty_when_no_newer_logs(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();

        $log = SyncHttpLog::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'direction' => 'out',
            'method' => 'GET',
            'url' => 'https://api.mercadolibre.com/orders/1',
            'endpoint_group' => 'orders',
            'response_status' => 200,
        ]);

        $this->getJson(route('sync-logs.list-updates', [
            'since_id' => $log->id,
        ]))
            ->assertOk()
            ->assertJson([
                'data' => [],
                'count' => 0,
            ]);
    }

    #[Test]
    public function show_includes_enriched_meta_fields(): void
    {
        [, $workspace, $connection] = $this->actingMemberWithMeliConnection();

        $log = SyncHttpLog::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'direction' => 'out',
            'correlation_id' => 'corr-meta',
            'method' => 'POST',
            'url' => 'https://api.mercadolibre.com/orders',
            'endpoint_group' => 'orders',
            'response_status' => 500,
            'latency_ms' => 90,
            'request_bytes' => 12,
            'response_bytes' => 34,
            'error_redacted' => 'timeout',
            'request_body_redacted' => '{}',
            'response_body_redacted' => '{"error":true}',
        ]);

        $this->getJson(route('sync-logs.show', $log))
            ->assertOk()
            ->assertJsonPath('log.connection_id', $connection->id)
            ->assertJsonPath('log.error_redacted', 'timeout')
            ->assertJsonPath('log.request_bytes', 12)
            ->assertJsonPath('log.response_bytes', 34);
    }
}
