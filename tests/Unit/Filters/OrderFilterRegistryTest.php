<?php

declare(strict_types=1);

namespace Tests\Unit\Filters;

use App\Http\Filters\Orders\OrderFilterRegistry;
use App\Models\Connection;
use App\Models\Order;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderFilterRegistryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_filters_by_connection_and_status(): void
    {
        [$workspace, $connectionA, $connectionB] = $this->seedWorkspaceWithTwoConnections();

        $paidA = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connectionA->id,
            'external_order_id' => 'ORD-A-PAID',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '10',
            'ordered_at' => now(),
        ]);
        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connectionA->id,
            'external_order_id' => 'ORD-A-PENDING',
            'status' => 'pending',
            'currency_code' => 'MXN',
            'total_amount' => '10',
            'ordered_at' => now(),
        ]);
        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connectionB->id,
            'external_order_id' => 'ORD-B-PAID',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '10',
            'ordered_at' => now(),
        ]);

        $ids = (new OrderFilterRegistry)
            ->apply(Order::query()->where('workspace_id', $workspace->id), [
                'connection_id' => $connectionA->id,
                'status' => 'paid',
                'q' => '',
                'from' => '',
                'to' => '',
                'tab' => 'all',
            ])
            ->pluck('id')
            ->all();

        $this->assertSame([$paidA->id], $ids);
    }

    #[Test]
    public function it_maps_tab_to_status_when_status_empty(): void
    {
        [$workspace, $connection] = $this->seedWorkspace();

        $cancelled = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-CANCELLED',
            'status' => 'cancelled',
            'currency_code' => 'MXN',
            'total_amount' => '10',
            'ordered_at' => now(),
        ]);
        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-PAID',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '10',
            'ordered_at' => now(),
        ]);

        $ids = (new OrderFilterRegistry)
            ->apply(Order::query()->where('workspace_id', $workspace->id), [
                'connection_id' => null,
                'status' => null,
                'q' => '',
                'from' => '',
                'to' => '',
                'tab' => 'cancelled',
            ])
            ->pluck('id')
            ->all();

        $this->assertSame([$cancelled->id], $ids);
    }

    #[Test]
    public function it_maps_delivered_tab_to_delivered_status(): void
    {
        [$workspace, $connection] = $this->seedWorkspace();

        $delivered = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-DELIVERED',
            'status' => 'delivered',
            'currency_code' => 'MXN',
            'total_amount' => '10',
            'ordered_at' => now(),
        ]);
        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'ORD-SHIPPED',
            'status' => 'shipped',
            'currency_code' => 'MXN',
            'total_amount' => '10',
            'ordered_at' => now(),
        ]);

        $ids = (new OrderFilterRegistry)
            ->apply(Order::query()->where('workspace_id', $workspace->id), [
                'connection_id' => null,
                'status' => null,
                'q' => '',
                'from' => '',
                'to' => '',
                'tab' => 'delivered',
            ])
            ->pluck('id')
            ->all();

        $this->assertSame([$delivered->id], $ids);
    }

    #[Test]
    public function it_filters_by_date_range_and_search(): void
    {
        [$workspace, $connection] = $this->seedWorkspace();

        $inRange = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'FIND-ME-123',
            'buyer_external_id' => 'buyer-9',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '10',
            'ordered_at' => '2026-03-15 12:00:00',
        ]);
        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'FIND-ME-OLD',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '10',
            'ordered_at' => '2026-01-01 12:00:00',
        ]);

        $ids = (new OrderFilterRegistry)
            ->apply(Order::query()->where('workspace_id', $workspace->id), [
                'connection_id' => null,
                'status' => null,
                'q' => 'FIND-ME',
                'from' => '2026-03-01',
                'to' => '2026-03-31',
                'tab' => 'all',
            ])
            ->pluck('id')
            ->all();

        $this->assertSame([$inRange->id], $ids);
    }

    #[Test]
    public function it_filters_by_mexico_city_calendar_day_not_utc_date(): void
    {
        config(['app.business_timezone' => 'America/Mexico_City']);
        [$workspace, $connection] = $this->seedWorkspace();

        // 2026-08-04 01:36 UTC = 2026-08-03 19:36 America/Mexico_City
        $eveningMx = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'MX-EVENING',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '10',
            'ordered_at' => '2026-08-04 01:36:50',
        ]);

        $idsOnAug3 = (new OrderFilterRegistry)
            ->apply(Order::query()->where('workspace_id', $workspace->id), [
                'from' => '2026-08-03',
                'to' => '2026-08-03',
                'tab' => 'all',
            ])
            ->pluck('id')
            ->all();

        $idsOnAug4 = (new OrderFilterRegistry)
            ->apply(Order::query()->where('workspace_id', $workspace->id), [
                'from' => '2026-08-04',
                'to' => '2026-08-04',
                'tab' => 'all',
            ])
            ->pluck('id')
            ->all();

        $this->assertSame([$eveningMx->id], $idsOnAug3);
        $this->assertSame([], $idsOnAug4);
    }

    /**
     * @return array{0: Workspace, 1: Connection}
     */
    private function seedWorkspace(): array
    {
        $workspace = Workspace::factory()->create();
        $connection = Connection::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '1',
            'status' => 'active',
            'token_generation' => 1,
        ]);

        return [$workspace, $connection];
    }

    /**
     * @return array{0: Workspace, 1: Connection, 2: Connection}
     */
    private function seedWorkspaceWithTwoConnections(): array
    {
        $workspace = Workspace::factory()->create();
        $connectionA = Connection::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '1',
            'status' => 'active',
            'token_generation' => 1,
        ]);
        $connectionB = Connection::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '2',
            'status' => 'active',
            'token_generation' => 1,
        ]);

        return [$workspace, $connectionA, $connectionB];
    }
}
