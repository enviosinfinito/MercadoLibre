<?php

namespace Tests\Feature;

use App\Domain\PostSale\Actions\SyncClaimMessages;
use App\Domain\PostSale\Actions\UpsertCanonicalClaim;
use App\Jobs\ProcessCanonicalClaimJob;
use App\Jobs\SyncClaimMessagesJob;
use App\Models\Claim;
use App\Models\ClaimMessage;
use App\Models\Connection;
use App\Models\ConnectionSyncProfile;
use App\Models\EncryptedCredential;
use App\Models\Order;
use App\Models\RawResourceSnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClaimMediationMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Connection, 3: Order, 4: Claim}
     */
    private function setupClaimContext(bool $outboundDryRun = true): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['outbound_dry_run' => $outboundDryRun]);

        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        $connection = Connection::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '1522113841',
            'status' => 'active',
            'token_generation' => 1,
        ]);

        $credential = new EncryptedCredential(['connection_id' => $connection->id]);
        $credential->setPlainPayload([
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh',
            'user_id' => '1522113841',
        ]);
        $credential->save();

        ConnectionSyncProfile::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'resource_key' => 'claims',
            'enabled' => true,
            'config' => ['include' => ['raw_snapshot' => true]],
        ]);

        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => '2000017245334060',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => 100,
            'ordered_at' => now(),
        ]);

        $claim = app(UpsertCanonicalClaim::class)->execute($workspace->id, $connection->id, [
            'external_claim_id' => '5540843237',
            'order_id' => $order->id,
            'type' => 'mediations',
            'stage' => 'dispute',
            'status' => 'opened',
            'reason' => 'PDD9955',
            'reason_id' => 'PDD9955',
            'resource' => 'order',
            'resource_external_id' => $order->external_order_id,
            'opened_at' => now(),
            'meta' => [
                'players' => [
                    [
                        'role' => 'respondent',
                        'type' => 'seller',
                        'user_id' => 1522113841,
                        'available_actions' => [
                            ['action' => 'send_message_to_mediator', 'mandatory' => false],
                        ],
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$user, $workspace, $connection, $order, $claim];
    }

    #[Test]
    public function sync_claim_messages_upserts_and_enriches(): void
    {
        [, , , $order, $claim] = $this->setupClaimContext();

        Http::fake([
            '*/post-purchase/v1/claims/5540843237/messages' => Http::response([
                [
                    'sender_role' => 'mediator',
                    'receiver_role' => 'respondent',
                    'message' => 'Hola, soy Karent de Mercado Libre.',
                    'date_created' => '2026-07-10T08:21:00.000-04:00',
                    'stage' => 'dispute',
                    'attachments' => [],
                ],
                [
                    'sender_role' => 'respondent',
                    'receiver_role' => 'mediator',
                    'message' => 'Gracias, quedo atento.',
                    'date_created' => '2026-07-10T09:00:00.000-04:00',
                    'stage' => 'dispute',
                ],
            ], 200),
            '*/post-purchase/v1/claims/reasons/PDD9955*' => Http::response([
                'id' => 'PDD9955',
                'detail' => 'El comprador dijo que el embalaje llegó bien pero recibió menos unidades.',
            ], 200),
            '*/post-purchase/v1/reasons/PDD9955*' => Http::response([
                'id' => 'PDD9955',
                'detail' => 'El comprador dijo que el embalaje llegó bien pero recibió menos unidades.',
            ], 200),
            '*/post-purchase/v1/claims/5540843237/affects-reputation*' => Http::response([
                'affects_reputation' => 'not_affected',
            ], 200),
            '*/post-purchase/v1/claims/5540843237/detail*' => Http::response([
                'due_date' => '2026-07-14T22:34:00.000-04:00',
                'action_responsible' => 'mediator',
                'title' => 'Mediación en espera de respuesta de Mercado Libre',
                'description' => 'Te escribiremos antes del martes 14 de julio.',
                'problem' => 'El comprador dijo que el embalaje llegó bien pero recibió menos unidades.',
            ], 200),
        ]);

        $payload = app(SyncClaimMessages::class)->execute($claim, refreshFromProvider: true);

        $this->assertCount(2, $payload['messages']);
        $this->assertSame('mediator', $payload['messages'][0]->sender_role);
        $claim->refresh();
        $this->assertSame('not_affected', $claim->affects_reputation);
        $this->assertStringContainsString('embalaje', (string) $claim->problem);
        $this->assertStringContainsString('Mediación en espera', (string) $claim->status_title);

        $show = $this->getJson(route('orders.show', $order));
        $show->assertOk()
            ->assertJsonPath('claims.0.external_claim_id', '5540843237')
            ->assertJsonPath('claims.0.can_message_mediator', true)
            ->assertJsonPath('claims.0.affects_reputation', 'not_affected')
            ->assertJsonPath('claims.0.resolution_reason', null);
    }

    #[Test]
    public function claim_messages_endpoint_returns_synced_thread(): void
    {
        [, , , $order, $claim] = $this->setupClaimContext();

        Http::fake([
            '*/post-purchase/v1/claims/5540843237/messages' => Http::response([
                [
                    'sender_role' => 'mediator',
                    'receiver_role' => 'respondent',
                    'message' => 'Estamos resolviendo el faltante.',
                    'date_created' => '2026-07-10T08:21:00.000-04:00',
                    'stage' => 'dispute',
                ],
            ], 200),
            '*/post-purchase/v1/claims/reasons/*' => Http::response(['detail' => 'Motivo'], 200),
            '*/post-purchase/v1/reasons/*' => Http::response(['detail' => 'Motivo'], 200),
            '*/affects-reputation*' => Http::response(['affects_reputation' => 'not_affected'], 200),
            '*/detail*' => Http::response([
                'problem' => 'El comprador dijo que el embalaje llegó bien pero recibió menos unidades.',
                'title' => 'Mediación en espera de respuesta de Mercado Libre',
                'description' => 'Te escribiremos antes del martes 14 de julio.',
                'due_date' => '2026-07-14T22:34:00.000-04:00',
            ], 200),
        ]);

        $response = $this->getJson(route('orders.claims.messages', [$order, $claim]));

        $response->assertOk()
            ->assertJsonPath('messages.0.message', 'Estamos resolviendo el faltante.')
            ->assertJsonPath('claim.external_claim_id', '5540843237');
    }

    #[Test]
    public function send_claim_message_dry_run_persists_local_message(): void
    {
        [, , , $order, $claim] = $this->setupClaimContext(outboundDryRun: true);

        $response = $this->postJson(route('orders.claims.messages.send', [$order, $claim]), [
            'text' => 'Hola mediador, quedo atento.',
        ]);

        $response->assertOk()
            ->assertJsonPath('messages.0.message', 'Hola mediador, quedo atento.')
            ->assertJsonPath('messages.0.sender_role', 'respondent');

        $this->assertSame(1, ClaimMessage::query()->where('claim_id', $claim->id)->count());
    }

    #[Test]
    public function claim_attachment_endpoint_proxies_ml_download(): void
    {
        [, , , $order, $claim] = $this->setupClaimContext();

        $filename = '646059146_5116ebc3-bc33-4332-85ed-6bd0fc74eab2.jpeg';
        ClaimMessage::query()->create([
            'workspace_id' => $claim->workspace_id,
            'connection_id' => $claim->connection_id,
            'claim_id' => $claim->id,
            'external_message_key' => 'att-msg-1',
            'sender_role' => 'complainant',
            'receiver_role' => 'respondent',
            'stage' => 'claim',
            'message' => 'El espejo viene roto',
            'sent_at' => now(),
            'meta' => [
                'attachments' => [
                    [
                        'filename' => $filename,
                        'original_filename' => 'IMG_2785.jpeg',
                        'type' => 'image/jpeg',
                        'size' => 12,
                    ],
                ],
            ],
        ]);

        $bytes = 'fake-jpeg-bytes';
        Http::fake([
            '*/post-purchase/v1/claims/5540843237/attachments/'.$filename.'/download' => Http::response(
                $bytes,
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        $response = $this->get(route('orders.claims.attachments', [
            'order' => $order,
            'claim' => $claim,
            'filename' => $filename,
        ]));

        $response->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('IMG_2785.jpeg', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame($bytes, $response->getContent());
    }

    #[Test]
    public function claim_attachment_endpoint_returns_404_for_unknown_filename(): void
    {
        [, , , $order, $claim] = $this->setupClaimContext();

        ClaimMessage::query()->create([
            'workspace_id' => $claim->workspace_id,
            'connection_id' => $claim->connection_id,
            'claim_id' => $claim->id,
            'external_message_key' => 'att-msg-2',
            'sender_role' => 'complainant',
            'receiver_role' => 'respondent',
            'message' => 'Sin adjuntos conocidos',
            'sent_at' => now(),
            'meta' => [
                'attachments' => [
                    [
                        'filename' => 'known-file.jpeg',
                        'original_filename' => 'known.jpeg',
                        'type' => 'image/jpeg',
                    ],
                ],
            ],
        ]);

        $response = $this->get(route('orders.claims.attachments', [
            'order' => $order,
            'claim' => $claim,
            'filename' => 'unknown-file.jpeg',
        ]));

        $response->assertNotFound();
    }

    #[Test]
    public function claim_attachment_endpoint_returns_404_for_foreign_claim(): void
    {
        [, $workspace, $connection, $order] = $this->setupClaimContext();

        $otherOrder = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => '2000017245334999',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => 50,
            'ordered_at' => now(),
        ]);

        $otherClaim = app(UpsertCanonicalClaim::class)->execute($workspace->id, $connection->id, [
            'external_claim_id' => '5540843999',
            'order_id' => $otherOrder->id,
            'type' => 'mediations',
            'stage' => 'dispute',
            'status' => 'opened',
            'reason_id' => 'PDD9955',
            'resource' => 'order',
            'resource_external_id' => $otherOrder->external_order_id,
            'opened_at' => now(),
            'meta' => [],
        ]);

        ClaimMessage::query()->create([
            'workspace_id' => $otherClaim->workspace_id,
            'connection_id' => $otherClaim->connection_id,
            'claim_id' => $otherClaim->id,
            'external_message_key' => 'att-msg-foreign',
            'sender_role' => 'complainant',
            'receiver_role' => 'respondent',
            'message' => 'Foto',
            'sent_at' => now(),
            'meta' => [
                'attachments' => [
                    [
                        'filename' => 'foreign-file.jpeg',
                        'original_filename' => 'foreign.jpeg',
                        'type' => 'image/jpeg',
                    ],
                ],
            ],
        ]);

        $response = $this->get(route('orders.claims.attachments', [
            'order' => $order,
            'claim' => $otherClaim,
            'filename' => 'foreign-file.jpeg',
        ]));

        $response->assertNotFound();
    }

    #[Test]
    public function process_canonical_claim_dispatches_messages_job(): void
    {
        [, $workspace, $connection, $order] = $this->setupClaimContext();
        Queue::fake([SyncClaimMessagesJob::class]);

        $snapshot = RawResourceSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'resource_type' => 'claim',
            'external_id' => '5540843237',
            'payload' => [
                'id' => 5540843237,
                'resource_id' => (int) $order->external_order_id,
                'status' => 'opened',
                'type' => 'mediations',
                'stage' => 'dispute',
                'resource' => 'order',
                'reason_id' => 'PDD9955',
                'date_created' => '2026-07-09T12:00:00.000-00:00',
                'players' => [],
            ],
            'checksum' => 'c-msg',
            'fetched_at' => now(),
        ]);

        (new ProcessCanonicalClaimJob(
            $workspace->id,
            $connection->id,
            (int) $snapshot->id,
        ))->handle();

        $claim = Claim::query()
            ->where('external_claim_id', '5540843237')
            ->firstOrFail();

        Queue::assertPushed(SyncClaimMessagesJob::class, function (SyncClaimMessagesJob $job) use ($claim) {
            return $job->claimId === $claim->id;
        });
    }
}
