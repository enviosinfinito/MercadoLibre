<?php

namespace Tests\Unit;

use App\Domain\PostSale\Actions\ResolveOrderPostSaleOutcome;
use App\Models\Connection;
use App\Models\Order;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderSalesClassificationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function scopes_classify_successful_cancelled_and_reversed_orders(): void
    {
        $workspace = Workspace::factory()->create();
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
        ]);

        $successful = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'S-1',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '10',
            'ordered_at' => now(),
        ]);

        $claimOpen = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'S-2',
            'status' => 'paid',
            'post_sale_outcome' => ResolveOrderPostSaleOutcome::CLAIM_OPEN,
            'currency_code' => 'MXN',
            'total_amount' => '10',
            'ordered_at' => now(),
        ]);

        $cancelled = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'C-1',
            'status' => 'canceled',
            'currency_code' => 'MXN',
            'total_amount' => '10',
            'ordered_at' => now(),
        ]);

        $returned = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'R-1',
            'status' => 'delivered',
            'post_sale_outcome' => ResolveOrderPostSaleOutcome::RETURNED,
            'currency_code' => 'MXN',
            'total_amount' => '10',
            'ordered_at' => now(),
        ]);

        $successfulIds = Order::query()->successful()->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$successful->id, $claimOpen->id], $successfulIds);

        $this->assertSame([$cancelled->id], Order::query()->cancelledStatus()->pluck('id')->all());
        $this->assertSame([$returned->id], Order::query()->postSaleReversed()->pluck('id')->all());
        $this->assertSame([$claimOpen->id], Order::query()->claimOpen()->pluck('id')->all());
    }
}
