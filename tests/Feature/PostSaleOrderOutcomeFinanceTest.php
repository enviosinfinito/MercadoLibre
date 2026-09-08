<?php

namespace Tests\Feature;

use App\Domain\Finance\Actions\CalculateExpectedProfit;
use App\Domain\Finance\Actions\RefreshExpectedOrderFinance;
use App\Domain\PostSale\Actions\ResolveOrderPostSaleOutcome;
use App\Domain\PostSale\Actions\UpsertCanonicalClaim;
use App\Models\Connection;
use App\Models\EncryptedCredential;
use App\Models\FinancialEvent;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PostSaleOrderOutcomeFinanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Http::fake();
    }

    /**
     * @return array{0: Workspace, 1: Connection, 2: Order}
     */
    private function seedOrderWithLine(string $lineTotal = '10199.000000'): array
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

        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => '2000016383039434',
            'status' => 'delivered',
            'currency_code' => 'MXN',
            'total_amount' => $lineTotal,
            'ordered_at' => now()->subMonths(3),
            'paid_at' => now()->subMonths(3),
        ]);

        OrderLine::query()->create([
            'order_id' => $order->id,
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'sku' => 'RB0101014P025601101',
            'title' => 'iPhone 14 Pro',
            'quantity' => '1.000000',
            'unit_price_amount' => $lineTotal,
            'currency_code' => 'MXN',
            'line_total_amount' => $lineTotal,
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$workspace, $connection, $order];
    }

    #[Test]
    public function closed_item_returned_sets_outcome_and_expected_refund(): void
    {
        [$workspace, $connection, $order] = $this->seedOrderWithLine();

        // Seed fees/taxes that the claim reversal must clear.
        FinancialEvent::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'order_line_id' => $order->lines()->first()->id,
            'event_type' => 'expected_fee_sale',
            'stage' => 'expected',
            'amount' => '-1121.890000',
            'currency_code' => 'MXN',
            'reporting_amount' => '-1121.890000',
            'reporting_currency' => 'MXN',
            'occurred_at' => now(),
            'provenance' => ['source' => 'test'],
        ]);
        FinancialEvent::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'order_line_id' => $order->lines()->first()->id,
            'event_type' => 'expected_tax_isr',
            'stage' => 'expected',
            'amount' => '-219.810000',
            'currency_code' => 'MXN',
            'reporting_amount' => '-219.810000',
            'reporting_currency' => 'MXN',
            'occurred_at' => now(),
            'provenance' => ['source' => 'test'],
        ]);

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

        $order->refresh();
        $this->assertSame(ResolveOrderPostSaleOutcome::RETURNED, $order->post_sale_outcome);

        $refund = FinancialEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', 'expected_refund')
            ->where('stage', 'expected')
            ->first();

        $this->assertNotNull($refund);
        $this->assertSame('-10199.000000', (string) $refund->amount);
        $this->assertSame('claim_resolution', $refund->provenance['source'] ?? null);

        $this->assertSame(
            0,
            FinancialEvent::query()
                ->where('order_id', $order->id)
                ->where('stage', 'expected')
                ->whereIn('event_type', [
                    'expected_fee_sale',
                    'expected_fee',
                    'expected_tax_isr',
                    'expected_tax_iva',
                    'expected_shipping_cost',
                ])
                ->count(),
        );

        $profit = app(CalculateExpectedProfit::class)->execute($order->fresh(['lines']));
        $this->assertSame('0.000000', (string) $profit->revenue_amount);
        $this->assertSame('0.000000', (string) $profit->fees_amount);
        $this->assertTrue((bool) ($profit->payload['sale_reversed'] ?? false));
        $this->assertNotEmpty($profit->payload['breakdown']['refunds'] ?? []);

        $show = $this->getJson(route('orders.show', $order));
        $show->assertOk()
            ->assertJsonPath('order.post_sale_outcome', 'returned')
            ->assertJsonPath('claims.0.resolution_reason', 'item_returned');

        $timelineKeys = collect($show->json('timeline'))->pluck('key')->all();
        $this->assertContains('returned', $timelineKeys);
    }

    #[Test]
    public function refresh_keeps_full_reversal_without_reseeding_fees(): void
    {
        [$workspace, $connection, $order] = $this->seedOrderWithLine('500.000000');

        app(UpsertCanonicalClaim::class)->execute($workspace->id, $connection->id, [
            'external_claim_id' => '9001',
            'order_id' => $order->id,
            'type' => 'mediations',
            'stage' => 'dispute',
            'status' => 'closed',
            'meta' => [
                'resolution' => ['reason' => 'payment_refunded'],
            ],
        ]);

        $order->refresh();
        $this->assertSame(ResolveOrderPostSaleOutcome::REFUNDED, $order->post_sale_outcome);

        $refresher = app(RefreshExpectedOrderFinance::class);
        $this->assertFalse($refresher->needsRefresh($order->fresh()));

        $refresher->execute($order->fresh(['lines']));

        $this->assertSame(
            0,
            FinancialEvent::query()
                ->where('order_id', $order->id)
                ->where('stage', 'expected')
                ->whereIn('event_type', ['expected_fee_sale', 'expected_tax_isr'])
                ->count(),
        );
        $this->assertSame(1, FinancialEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', 'expected_refund')
            ->count());
    }
}
