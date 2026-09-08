<?php

namespace Tests\Feature;

use App\Jobs\ProcessCanonicalOrderJob;
use App\Models\Connection;
use App\Models\ConnectionSyncProfile;
use App\Models\Order;
use App\Models\RawResourceSnapshot;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderDateTimezoneSyncTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function process_canonical_order_stores_date_created_as_utc_instant(): void
    {
        [$workspace, $connection] = $this->seedConnection();

        $snapshot = RawResourceSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'resource_type' => 'order',
            'external_id' => '2000017743997018',
            'payload' => [
                'id' => 2000017743997018,
                'status' => 'paid',
                'currency_id' => 'MXN',
                'total_amount' => 199,
                'date_created' => '2026-08-04T01:36:50.000-04:00',
                'date_closed' => '2026-08-04T01:37:19.000-04:00',
                'payments' => [
                    [
                        'id' => 99,
                        'date_approved' => '2026-08-04T01:37:00.000-04:00',
                    ],
                ],
                'buyer' => ['id' => 555],
                'order_items' => [
                    [
                        'quantity' => 1,
                        'unit_price' => 199,
                        'item' => [
                            'id' => 'MLM1',
                            'title' => 'Demo',
                            'seller_sku' => 'SKU-TZ',
                        ],
                    ],
                ],
            ],
            'checksum' => 'tz-test',
            'fetched_at' => now(),
        ]);

        (new ProcessCanonicalOrderJob(
            (int) $workspace->id,
            (int) $connection->id,
            (int) $snapshot->id,
            applyInventoryEffects: false,
            projectSynchronously: true,
        ))->handle();

        $order = Order::query()->where('external_order_id', '2000017743997018')->first();
        $this->assertNotNull($order);
        $this->assertSame('2026-08-04 05:36:50', $order->ordered_at?->timezone('UTC')->toDateTimeString());
        $this->assertSame('2026-08-04 05:37:00', $order->paid_at?->timezone('UTC')->toDateTimeString());
    }

    #[Test]
    public function backfill_order_dates_corrects_offset_stripped_rows(): void
    {
        [$workspace, $connection] = $this->seedConnection();

        $snapshot = RawResourceSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'resource_type' => 'order',
            'external_id' => '2000017743997019',
            'payload' => [
                'id' => 2000017743997019,
                'status' => 'paid',
                'date_created' => '2026-08-04T01:36:50.000-04:00',
                'date_closed' => '2026-08-04T01:37:19.000-04:00',
                'payments' => [
                    ['id' => 1, 'date_approved' => '2026-08-04T01:37:00.000-04:00'],
                ],
            ],
            'checksum' => 'tz-backfill',
            'fetched_at' => now(),
        ]);

        $order = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => '2000017743997019',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '10',
            // Wall-clock of -04:00 incorrectly stored as UTC (legacy bug).
            'ordered_at' => '2026-08-04 01:36:50',
            'paid_at' => '2026-08-04 01:37:19',
            'raw_snapshot_id' => $snapshot->id,
        ]);

        Artisan::call('sync:backfill-order-dates', [
            '--workspace' => $workspace->id,
        ]);

        $order->refresh();
        $this->assertSame('2026-08-04 05:36:50', $order->ordered_at?->timezone('UTC')->toDateTimeString());
        $this->assertSame('2026-08-04 05:37:00', $order->paid_at?->timezone('UTC')->toDateTimeString());
    }

    /**
     * @return array{0: Workspace, 1: Connection}
     */
    private function seedConnection(): array
    {
        $workspace = Workspace::factory()->create();
        $connection = Connection::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '112184176',
            'status' => 'active',
            'token_generation' => 1,
        ]);

        ConnectionSyncProfile::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'resource_key' => 'orders',
            'enabled' => true,
            'config' => [
                'include' => ['raw_snapshot' => true, 'lines' => true, 'buyer' => true],
            ],
        ]);

        return [$workspace, $connection];
    }
}
