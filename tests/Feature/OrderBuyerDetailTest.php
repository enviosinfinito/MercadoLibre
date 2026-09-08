<?php

namespace Tests\Feature;

use App\Domain\Sales\Support\BuyerPresentation;
use App\Jobs\ProcessCanonicalOrderJob;
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
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderBuyerDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    #[Test]
    public function buyer_presentation_builds_summary_and_meta(): void
    {
        $meta = BuyerPresentation::metaFromProviderBuyer([
            'id' => 529340135,
            'nickname' => 'COMPRADORTEST',
            'first_name' => 'Ana',
            'last_name' => 'López',
            'email' => 'ana@example.com',
            'phone' => ['area_code' => '55', 'number' => '1234-5678', 'verified' => true],
            'billing_info' => ['doc_type' => 'RFC', 'doc_number' => 'XAXX010101000'],
            'noise' => 'ignore',
        ]);

        $this->assertSame('529340135', $meta['id']);
        $this->assertSame('COMPRADORTEST', $meta['nickname']);
        $this->assertArrayNotHasKey('noise', $meta);
        $this->assertSame(
            'COMPRADORTEST · Ana López',
            BuyerPresentation::summary('529340135', $meta),
        );
        $this->assertSame('55 1234-5678', BuyerPresentation::formatPhone($meta['phone']));
    }

    #[Test]
    public function process_canonical_order_persists_buyer_meta(): void
    {
        Queue::fake();

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
            'config' => [
                'include' => [
                    'raw_snapshot' => true,
                    'lines' => true,
                    'buyer' => true,
                ],
            ],
        ]);

        $snapshot = RawResourceSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'resource_type' => 'order',
            'external_id' => '200000777',
            'payload' => [
                'id' => '200000777',
                'status' => 'paid',
                'currency_id' => 'MXN',
                'total_amount' => 50,
                'date_created' => '2026-08-03T03:17:00.000-00:00',
                'date_closed' => '2026-08-03T03:17:00.000-00:00',
                'buyer' => [
                    'id' => 529340135,
                    'nickname' => 'COMPRADORTEST',
                    'first_name' => 'Ana',
                    'last_name' => 'López',
                    'email' => 'ana@example.com',
                    'phone' => [
                        'area_code' => '55',
                        'number' => '1234-5678',
                        'verified' => true,
                    ],
                    'billing_info' => [
                        'doc_type' => 'RFC',
                        'doc_number' => 'XAXX010101000',
                    ],
                ],
                'order_items' => [],
            ],
            'checksum' => 'buyer-meta',
            'fetched_at' => now(),
        ]);

        (new ProcessCanonicalOrderJob(
            $workspace->id,
            $connection->id,
            (int) $snapshot->id,
            applyInventoryEffects: false,
        ))->handle();

        $order = Order::query()->where('external_order_id', '200000777')->firstOrFail();
        $this->assertSame('529340135', $order->buyer_external_id);
        $this->assertSame('COMPRADORTEST', $order->meta['buyer']['nickname'] ?? null);
        $this->assertSame('Ana', $order->meta['buyer']['first_name'] ?? null);
        $this->assertSame('RFC', $order->meta['buyer']['billing_info']['doc_type'] ?? null);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);
        $response = $this->getJson(route('orders.show', $order));
        $response->assertOk();
        $response->assertJsonPath('order.meta.buyer.nickname', 'COMPRADORTEST');
    }
}
