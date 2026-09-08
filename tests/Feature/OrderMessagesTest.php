<?php

namespace Tests\Feature;

use App\Domain\Sales\Actions\UpsertCanonicalOrder;
use App\Models\Connection;
use App\Models\EncryptedCredential;
use App\Models\Order;
use App\Models\OrderMessage;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Connection, 3: Order}
     */
    private function actingMemberWithOrder(bool $outboundDryRun = true): array
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
            'external_user_id' => '112184176',
            'site_id' => 'MLM',
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

        $order = app(UpsertCanonicalOrder::class)->execute($workspace->id, $connection->id, [
            'external_order_id' => '200000777',
            'external_pack_id' => '200000777',
            'status' => 'paid',
            'buyer_external_id' => '555001',
            'currency_code' => 'MXN',
            'total_amount' => '100',
            'meta' => ['messaging_pack_id' => '200000777'],
            'lines' => [],
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$user, $workspace, $connection, $order];
    }

    #[Test]
    public function messages_sync_from_pack_endpoint(): void
    {
        [, , $connection, $order] = $this->actingMemberWithOrder();

        Http::fake([
            '*/messages/packs/200000777/sellers/112184176*' => Http::response([
                'paging' => ['total' => 2, 'limit' => 50, 'offset' => 0],
                'conversation_status' => ['status' => 'active'],
                'seller_max_message_length' => 350,
                'messages' => [
                    [
                        'id' => 'msg-buyer-1',
                        'from' => ['user_id' => 555001],
                        'to' => ['user_id' => 112184176],
                        'status' => 'available',
                        'text' => 'Hola, ¿cuándo envían?',
                        'message_date' => [
                            'created' => '2026-08-05T10:00:00.000Z',
                            'read' => null,
                        ],
                    ],
                    [
                        'id' => 'msg-seller-1',
                        'from' => ['user_id' => 112184176],
                        'to' => ['user_id' => 555001],
                        'status' => 'available',
                        'text' => 'Hoy sale el paquete.',
                        'message_date' => [
                            'created' => '2026-08-05T11:00:00.000Z',
                            'read' => null,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->getJson(route('orders.messages', $order));

        $response->assertOk()
            ->assertJsonPath('order.messaging_pack_id', '200000777')
            ->assertJsonCount(2, 'messages');

        $this->assertDatabaseHas('order_messages', [
            'order_id' => $order->id,
            'external_message_id' => 'msg-buyer-1',
            'direction' => 'inbound',
        ]);
        $this->assertDatabaseHas('order_messages', [
            'order_id' => $order->id,
            'external_message_id' => 'msg-seller-1',
            'direction' => 'outbound',
        ]);
        $this->assertSame($connection->id, OrderMessage::query()->first()->connection_id);
    }

    #[Test]
    public function send_message_dry_run_persists_outbound(): void
    {
        [, , , $order] = $this->actingMemberWithOrder(outboundDryRun: true);

        $response = $this->postJson(route('orders.messages.send', $order), [
            'text' => 'Gracias por tu compra.',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('order_messages', [
            'order_id' => $order->id,
            'direction' => 'outbound',
            'text' => 'Gracias por tu compra.',
        ]);
    }

    #[Test]
    public function messages_returns_404_for_other_workspace(): void
    {
        [, , $connection] = $this->actingMemberWithOrder();
        $otherWorkspace = Workspace::factory()->create();
        $otherOrder = Order::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'foreign-msg',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '10',
            'ordered_at' => now(),
        ]);

        $this->getJson(route('orders.messages', $otherOrder))->assertNotFound();
    }
}
