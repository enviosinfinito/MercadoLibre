<?php

namespace Tests\Feature;

use App\Domain\Inventory\Actions\SyncMercadoLibreFullStockOperations;
use App\Jobs\BootstrapMercadoLibreFullStockOperationsJob;
use App\Models\ChannelListing;
use App\Models\ChannelListingVariant;
use App\Models\Connection;
use App\Models\EncryptedCredential;
use App\Models\FullStockOperation;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReturnCase;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Variant;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FullStockOperationsSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Connection, 3: Variant, 4: ChannelListingVariant}
     */
    private function seedWorkspaceWithFullListing(): array
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
            'external_user_id' => '384324657',
            'display_name' => 'Tienda Full',
            'color' => '#0f766e',
            'status' => 'active',
            'token_generation' => 1,
        ]);

        $credential = new EncryptedCredential(['connection_id' => $connection->id]);
        $credential->setPlainPayload([
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh',
            'user_id' => '384324657',
            'expires_at' => now()->addHours(6)->toIso8601String(),
        ]);
        $credential->save();

        $product = Product::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Full Demo',
            'status' => 'active',
        ]);

        $variant = Variant::query()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'sku' => 'FULL-001',
            'name' => 'Default',
            'status' => 'active',
        ]);

        $listing = ChannelListing::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'external_item_id' => 'MLM123',
            'title' => 'Full Demo Listing',
            'status' => 'active',
            'logistic_type' => 'fulfillment',
            'inventory_id' => 'DEHW09303',
        ]);

        $clv = ChannelListingVariant::query()->create([
            'workspace_id' => $workspace->id,
            'channel_listing_id' => $listing->id,
            'variant_id' => $variant->id,
            'inventory_id' => 'DEHW09303',
            'sku_external' => 'FULL-001',
            'status' => 'active',
            'available_quantity' => 10,
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$user, $workspace, $connection, $variant, $clv];
    }

    private function fakeOperationsHttp(bool $withSecondPage = false): void
    {
        Http::preventStrayRequests();

        $page = 0;
        Http::fake(function (Request $request) use (&$page, $withSecondPage) {
            $url = $request->url();

            if (! str_contains($url, '/stock/fulfillment/operations/search')) {
                return Http::response(['error' => 'unmocked', 'url' => $url], 500);
            }

            $page++;
            if ($withSecondPage && $page === 1) {
                return Http::response([
                    'paging' => ['total' => 2, 'scroll' => 'scroll-token-1'],
                    'results' => [
                        [
                            'id' => 306705012,
                            'seller_id' => 384324657,
                            'inventory_id' => 'DEHW09303',
                            'seller_product_id' => 'DEHW09303',
                            'date_created' => '2020-06-18T17:55:42Z',
                            'type' => 'INBOUND_RECEPTION',
                            'detail' => [
                                'available_quantity' => 100,
                                'not_available_detail' => [],
                            ],
                            'result' => [
                                'total' => 100,
                                'available_quantity' => 100,
                                'not_available_quantity' => 0,
                                'not_available_detail' => [],
                            ],
                            'external_references' => [
                                ['type' => 'inbound_id', 'value' => '0001'],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response([
                'paging' => ['total' => $withSecondPage ? 2 : 2, 'scroll' => ''],
                'results' => [
                    [
                        'id' => 306718974,
                        'seller_id' => 384324657,
                        'inventory_id' => 'DEHW09303',
                        'seller_product_id' => 'DEHW09303',
                        'date_created' => '2020-06-18T18:02:33Z',
                        'type' => 'SALE_CONFIRMATION',
                        'detail' => [
                            'available_quantity' => -10,
                            'not_available_detail' => [],
                        ],
                        'result' => [
                            'total' => 90,
                            'available_quantity' => 90,
                            'not_available_quantity' => 0,
                            'not_available_detail' => [],
                        ],
                        'external_references' => [
                            ['type' => 'shipment_id', 'value' => '28312961122'],
                        ],
                    ],
                    [
                        'id' => 306811273,
                        'seller_id' => 384324657,
                        'inventory_id' => 'UNKNOWN99',
                        'date_created' => '2020-06-18T18:43:26Z',
                        'type' => 'STOCK_AUDIT',
                        'detail' => [
                            'available_quantity' => -5,
                            'not_available_quantity' => 5,
                            'not_available_detail' => [
                                ['status' => 'lost', 'quantity' => 5],
                            ],
                        ],
                        'result' => [
                            'total' => 100,
                            'available_quantity' => 95,
                            'not_available_quantity' => 5,
                            'not_available_detail' => [
                                ['status' => 'lost', 'quantity' => 5],
                            ],
                        ],
                        'external_references' => [],
                    ],
                ],
            ], 200);
        });
    }

    #[Test]
    public function sync_upserts_operations_segmented_by_type_and_links_inventory(): void
    {
        [, $workspace, $connection, $variant] = $this->seedWorkspaceWithFullListing();
        $this->fakeOperationsHttp();

        $stats = app(SyncMercadoLibreFullStockOperations::class)->execute(
            $connection,
            lookbackDays: 15,
        );

        $this->assertSame(2, $stats['fetched']);
        $this->assertSame(2, $stats['upserted']);
        $this->assertSame(1, $stats['by_type']['SALE_CONFIRMATION'] ?? 0);
        $this->assertSame(1, $stats['by_type']['STOCK_AUDIT'] ?? 0);
        $this->assertContains('UNKNOWN99', $stats['unmatched_inventory_ids']);

        $sale = FullStockOperation::query()
            ->where('external_operation_id', '306718974')
            ->first();

        $this->assertNotNull($sale);
        $this->assertSame($workspace->id, $sale->workspace_id);
        $this->assertSame('SALE_CONFIRMATION', $sale->operation_type);
        $this->assertSame('DEHW09303', $sale->inventory_id);
        $this->assertSame($variant->id, $sale->variant_id);
        $this->assertSame('-10.000000', (string) $sale->available_quantity_delta);
        $this->assertSame('90.000000', (string) $sale->result_available);
        $this->assertSame('28312961122', $sale->external_references[0]['value'] ?? null);
    }

    #[Test]
    public function sync_is_idempotent_on_re_run(): void
    {
        [, , $connection] = $this->seedWorkspaceWithFullListing();
        $this->fakeOperationsHttp();

        $sync = app(SyncMercadoLibreFullStockOperations::class);
        $sync->execute($connection, lookbackDays: 15);
        $sync->execute($connection, lookbackDays: 15);

        $this->assertSame(2, FullStockOperation::query()->count());
    }

    #[Test]
    public function sync_follows_scroll_pagination(): void
    {
        [, , $connection] = $this->seedWorkspaceWithFullListing();
        $this->fakeOperationsHttp(withSecondPage: true);

        $stats = app(SyncMercadoLibreFullStockOperations::class)->execute(
            $connection,
            lookbackDays: 15,
        );

        $this->assertSame(3, $stats['fetched']);
        $this->assertSame(3, FullStockOperation::query()->count());
        $this->assertTrue(
            FullStockOperation::query()->where('operation_type', 'INBOUND_RECEPTION')->exists()
        );
    }

    #[Test]
    public function index_filters_by_operation_type_and_sku(): void
    {
        [, $workspace, $connection, $variant] = $this->seedWorkspaceWithFullListing();

        FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_operation_id' => '1',
            'operation_type' => 'INBOUND_RECEPTION',
            'inventory_id' => 'DEHW09303',
            'variant_id' => $variant->id,
            'occurred_at' => now()->subDay(),
        ]);

        FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_operation_id' => '2',
            'operation_type' => 'SALE_CONFIRMATION',
            'inventory_id' => 'OTHER',
            'variant_id' => null,
            'occurred_at' => now()->subHours(2),
        ]);

        $this->get(route('inventory.full-operations.index', [
            'operation_type' => 'INBOUND_RECEPTION',
        ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Inventory/FullOperations')
                ->has('entries.data', 1)
                ->where('entries.data.0.operation_type', 'INBOUND_RECEPTION')
                ->where('entries.data.0.connection.color', '#0f766e')
                ->where('type_counts.all', 2)
                ->where('type_counts.inbound', 1)
                ->where('type_counts.sale', 1)
                ->has('today_summary')
                ->where('filters.tab', 'all'));

        $this->get(route('inventory.full-operations.index', [
            'q' => 'FULL-001',
        ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Inventory/FullOperations')
                ->has('entries.data', 1)
                ->where('entries.data.0.variant.sku', 'FULL-001'));

        $this->get(route('inventory.full-operations.index', [
            'tab' => 'sale',
        ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Inventory/FullOperations')
                ->has('entries.data', 1)
                ->where('entries.data.0.operation_type', 'SALE_CONFIRMATION')
                ->where('filters.tab', 'sale'));
    }

    #[Test]
    public function index_returns_json_paginator_without_kpis(): void
    {
        [, $workspace, $connection] = $this->seedWorkspaceWithFullListing();

        FullStockOperation::factory()->count(51)->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
        ]);

        $this->getJson(route('inventory.full-operations.index', ['page' => 2]))
            ->assertOk()
            ->assertJsonPath('current_page', 2)
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('type_counts')
            ->assertJsonMissingPath('today_summary');
    }

    #[Test]
    public function sync_endpoint_queues_job(): void
    {
        [, , $connection] = $this->seedWorkspaceWithFullListing();
        Queue::fake();

        $this->post(route('inventory.full-operations.sync'), [
            'connection_id' => $connection->id,
            'days' => 30,
        ])->assertRedirect();

        Queue::assertPushed(BootstrapMercadoLibreFullStockOperationsJob::class);
    }

    #[Test]
    public function show_returns_json_detail_with_related_order(): void
    {
        [, $workspace, $connection, $variant] = $this->seedWorkspaceWithFullListing();

        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-999',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '100.000000',
            'ordered_at' => now(),
        ]);

        Shipment::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'external_shipment_id' => '47745653566',
            'status' => 'shipped',
        ]);

        $op = FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_operation_id' => '999001',
            'operation_type' => 'SALE_CONFIRMATION',
            'inventory_id' => 'DEHW09303',
            'variant_id' => $variant->id,
            'available_quantity_delta' => '-1',
            'result_available' => '4',
            'external_references' => [
                ['type' => 'shipment_id', 'value' => '47745653566'],
            ],
            'raw' => [
                'id' => 999001,
                'type' => 'SALE_CONFIRMATION',
            ],
            'occurred_at' => now(),
        ]);

        $this->getJson(route('inventory.full-operations.show', $op))
            ->assertOk()
            ->assertJsonPath('operation.operation_type', 'SALE_CONFIRMATION')
            ->assertJsonPath('operation.external_operation_id', '999001')
            ->assertJsonPath('operation.raw.id', 999001)
            ->assertJsonPath('related.variant.sku', 'FULL-001')
            ->assertJsonPath('related.shipment.external_shipment_id', '47745653566')
            ->assertJsonPath('related.order.id', $order->id)
            ->assertJsonPath('related.order.external_order_id', 'ORD-999');
    }

    #[Test]
    public function show_links_return_when_ref_is_order_external_id(): void
    {
        [, $workspace, $connection, $variant] = $this->seedWorkspaceWithFullListing();

        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => '2000017674820842',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '184.000000',
            'ordered_at' => now()->subDays(5),
        ]);

        $return = ReturnCase::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'external_return_id' => '5553660422',
            'status' => 'closed',
            'outcome' => 'returned',
            'reason_label' => 'No lo quiero',
            'opened_at' => now()->subDays(2),
            'closed_at' => now()->subDay(),
            'delivered_at' => now()->subDays(4),
            'days_to_return' => 2,
            'returned_amount' => '184.000000',
            'currency_code' => 'MXN',
        ]);

        $op = FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_operation_id' => '2149',
            'operation_type' => 'SALE_DELIVERY_CANCELATION',
            'inventory_id' => 'GBDQ03890',
            'variant_id' => $variant->id,
            'available_quantity_delta' => '1',
            'external_references' => [
                ['type' => 'shipment_id', 'value' => '2000017674820842'],
            ],
            'occurred_at' => now(),
        ]);

        $this->getJson(route('inventory.full-operations.show', $op))
            ->assertOk()
            ->assertJsonPath('related.order.id', $order->id)
            ->assertJsonPath('related.return.id', $return->id)
            ->assertJsonPath('related.return.outcome', 'returned')
            ->assertJsonPath('related.match_via', 'order_external_id')
            ->assertJsonPath('related.shipment', null);
    }

    #[Test]
    public function show_links_return_via_canonical_shipment(): void
    {
        [, $workspace, $connection] = $this->seedWorkspaceWithFullListing();

        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-SHIP-1',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '50.000000',
            'ordered_at' => now(),
        ]);

        Shipment::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'external_shipment_id' => 'SHIP-CANON-99',
            'status' => 'delivered',
        ]);

        $return = ReturnCase::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'external_return_id' => 'ret-ship-1',
            'status' => 'closed',
            'outcome' => 'returned',
            'opened_at' => now(),
        ]);

        $op = FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_operation_id' => 'ship-link-1',
            'operation_type' => 'ADJUSTMENT',
            'external_references' => [
                ['type' => 'shipment_id', 'value' => 'SHIP-CANON-99'],
            ],
            'not_available_quantity_delta' => '1',
            'occurred_at' => now(),
        ]);

        $this->getJson(route('inventory.full-operations.show', $op))
            ->assertOk()
            ->assertJsonPath('related.shipment.external_shipment_id', 'SHIP-CANON-99')
            ->assertJsonPath('related.order.id', $order->id)
            ->assertJsonPath('related.return.id', $return->id)
            ->assertJsonPath('related.match_via', 'shipment_external_id');
    }

    #[Test]
    public function show_related_return_is_null_without_match(): void
    {
        [, $workspace, $connection] = $this->seedWorkspaceWithFullListing();

        $op = FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_operation_id' => 'no-match',
            'operation_type' => 'ADJUSTMENT',
            'external_references' => [
                ['type' => 'shipment_id', 'value' => 'DOES-NOT-EXIST'],
            ],
            'occurred_at' => now(),
        ]);

        $this->getJson(route('inventory.full-operations.show', $op))
            ->assertOk()
            ->assertJsonPath('related.return', null)
            ->assertJsonPath('related.order', null)
            ->assertJsonPath('related.shipment.unmatched', true);
    }

    #[Test]
    public function return_show_includes_linked_full_operations(): void
    {
        [, $workspace, $connection] = $this->seedWorkspaceWithFullListing();

        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => '2000017686279014',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '479.000000',
            'ordered_at' => now()->subDays(3),
        ]);

        $return = ReturnCase::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'order_id' => $order->id,
            'external_return_id' => '5552942483',
            'status' => 'closed',
            'outcome' => 'returned',
            'opened_at' => now()->subDays(2),
            'closed_at' => now()->subDay(),
            'delivered_at' => now()->subDays(3),
        ]);

        FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_operation_id' => '1104',
            'operation_type' => 'ADJUSTMENT',
            'external_references' => [
                ['type' => 'shipment_id', 'value' => '2000017686279014'],
            ],
            'not_available_quantity_delta' => '1',
            'occurred_at' => now(),
        ]);

        $this->get(route('returns.items.show', $return))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Returns/Items/Show')
                ->has('item.full_operations', 1)
                ->where('item.full_operations.0.operation_type', 'ADJUSTMENT')
                ->where('item.full_operations.0.id', fn ($id) => (int) $id > 0));
    }

    #[Test]
    public function show_returns_404_for_other_workspace(): void
    {
        [, $workspace, $connection] = $this->seedWorkspaceWithFullListing();

        $op = FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_operation_id' => 'x1',
            'operation_type' => 'ADJUSTMENT',
        ]);

        $otherUser = User::factory()->create();
        $otherWorkspace = Workspace::factory()->create();
        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $otherWorkspace->id,
            'user_id' => $otherUser->id,
        ]);

        $this->actingAs($otherUser)->withSession(['workspace_id' => $otherWorkspace->id]);

        $this->getJson(route('inventory.full-operations.show', $op))
            ->assertNotFound();
    }
}
