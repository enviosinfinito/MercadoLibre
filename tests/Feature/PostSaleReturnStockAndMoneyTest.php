<?php

namespace Tests\Feature;

use App\Domain\Inventory\Actions\RestockOrderFromReturn;
use App\Domain\PostSale\Actions\ApplyOrderPostSaleEffects;
use App\Domain\PostSale\Actions\UpsertCanonicalClaim;
use App\Models\Connection;
use App\Models\EncryptedCredential;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLedger;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Models\Variant;
use App\Models\Warehouse;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PostSaleReturnStockAndMoneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Http::fake();
    }

    /**
     * @return array{0: Workspace, 1: Connection, 2: Order, 3: User}
     */
    private function seedWorkspaceOrder(?int $variantId = null): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['reporting_currency' => 'MXN']);
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

        Warehouse::factory()->default()->create([
            'workspace_id' => $workspace->id,
        ]);

        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => '2000016383039434',
            'status' => 'delivered',
            'currency_code' => 'MXN',
            'total_amount' => '10199.000000',
            'ordered_at' => now()->subMonths(3),
            'paid_at' => now()->subMonths(3),
        ]);

        OrderLine::query()->create([
            'order_id' => $order->id,
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'variant_id' => $variantId,
            'sku' => 'RB0101014P025601101',
            'title' => 'iPhone 14 Pro',
            'quantity' => '1.000000',
            'unit_price_amount' => '10199.000000',
            'currency_code' => 'MXN',
            'line_total_amount' => '10199.000000',
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$workspace, $connection, $order->fresh(['lines']), $user];
    }

    private function closeReturnedClaim(Workspace $workspace, Connection $connection, Order $order): void
    {
        app(UpsertCanonicalClaim::class)->execute($workspace->id, $connection->id, [
            'external_claim_id' => '5511709764',
            'order_id' => $order->id,
            'type' => 'mediations',
            'stage' => 'dispute',
            'status' => 'closed',
            'reason_id' => 'PDD9939',
            'resource' => 'order',
            'resource_external_id' => $order->external_order_id,
            'opened_at' => now()->subMonths(3),
            'closed_at' => now()->subMonths(2),
            'meta' => [
                'resolution' => [
                    'reason' => 'item_returned',
                    'closed_by' => 'mediator',
                    'date_created' => now()->subMonths(2)->toIso8601String(),
                ],
            ],
        ]);
    }

    #[Test]
    public function returned_without_variant_exposes_unmatched_stock_and_money(): void
    {
        [$workspace, $connection, $order] = $this->seedWorkspaceOrder(null);

        $this->closeReturnedClaim($workspace, $connection, $order);

        $show = $this->getJson(route('orders.show', $order));
        $show->assertOk()
            ->assertJsonPath('post_sale.outcome', 'returned')
            ->assertJsonPath('post_sale.narrative', 'inversion')
            ->assertJsonPath('post_sale.payment.inbound.amount', '10199.000000')
            ->assertJsonPath('post_sale.payment.inbound.label', 'Pago recibido')
            ->assertJsonPath('post_sale.payment.outbound.status', 'refunded')
            ->assertJsonPath('post_sale.payment.outbound.amount', '10199.000000')
            ->assertJsonPath('post_sale.fulfillment.inbound.status', 'unmatched')
            ->assertJsonPath('post_sale.money.status', 'refunded')
            ->assertJsonPath('post_sale.stock.status', 'unmatched');
    }

    #[Test]
    public function returned_with_variant_restocks_once(): void
    {
        [$workspace, $connection, $order] = $this->seedWorkspaceOrder(null);

        $variant = Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'sku' => 'RB0101014P025601101',
        ]);
        $order->lines()->first()->update(['variant_id' => $variant->id]);
        $order = $order->fresh(['lines']);

        $this->closeReturnedClaim($workspace, $connection, $order);

        $key = RestockOrderFromReturn::idempotencyKey((int) $order->id, (int) $order->lines()->first()->id);
        $this->assertSame(1, InventoryLedger::query()->where('idempotency_key', $key)->count());

        $item = InventoryItem::query()
            ->where('workspace_id', $workspace->id)
            ->where('variant_id', $variant->id)
            ->first();
        $this->assertNotNull($item);
        $balance = InventoryBalance::query()->where('inventory_item_id', $item->id)->first();
        $this->assertSame('1.000000', (string) $balance->quantity_on_hand);

        app(ApplyOrderPostSaleEffects::class)->execute($order->id);

        $this->assertSame(1, InventoryLedger::query()->where('idempotency_key', $key)->count());
        $balance->refresh();
        $this->assertSame('1.000000', (string) $balance->quantity_on_hand);

        $show = $this->getJson(route('orders.show', $order));
        $show->assertOk()
            ->assertJsonPath('post_sale.stock.status', 'restocked')
            ->assertJsonPath('post_sale.money.amount', '10199.000000');
    }
}
