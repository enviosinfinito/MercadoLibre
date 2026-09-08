<?php

namespace Tests\Feature;

use App\Domain\Sales\Actions\UpsertCanonicalOrder;
use App\Events\OrderUpdated;
use App\Models\Connection;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderUpdatedBroadcastTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function broadcast_payload_marks_new_orders(): void
    {
        $workspace = Workspace::factory()->create();
        $connection = Connection::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '999',
            'status' => 'active',
        ]);

        $first = app(UpsertCanonicalOrder::class)->executeWithMeta($workspace->id, $connection->id, [
            'external_order_id' => 'ORD-SOUND-1',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '100',
            'lines' => [],
        ]);

        $this->assertTrue($first->wasCreated);

        $event = new OrderUpdated($first->order, isNew: $first->wasCreated);
        $payload = $event->broadcastWith();

        $this->assertTrue($payload['is_new']);
        $this->assertSame($first->order->id, $payload['id']);
        $this->assertSame('ORD-SOUND-1', $payload['external_order_id']);
        $this->assertSame('order.updated', $event->broadcastAs());
        $this->assertInstanceOf(
            \Illuminate\Contracts\Broadcasting\ShouldBroadcastNow::class,
            $event,
        );
    }

    #[Test]
    public function broadcast_payload_marks_updates_as_not_new(): void
    {
        $workspace = Workspace::factory()->create();
        $connection = Connection::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '999',
            'status' => 'active',
        ]);

        app(UpsertCanonicalOrder::class)->execute($workspace->id, $connection->id, [
            'external_order_id' => 'ORD-SOUND-2',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '50',
            'lines' => [],
        ]);

        $second = app(UpsertCanonicalOrder::class)->executeWithMeta($workspace->id, $connection->id, [
            'external_order_id' => 'ORD-SOUND-2',
            'status' => 'shipped',
            'currency_code' => 'MXN',
            'total_amount' => '50',
            'lines' => [],
        ]);

        $this->assertFalse($second->wasCreated);

        $event = new OrderUpdated($second->order, isNew: $second->wasCreated);
        $this->assertFalse($event->broadcastWith()['is_new']);
    }

    #[Test]
    public function order_updated_event_is_dispatched_shape(): void
    {
        Event::fake([OrderUpdated::class]);

        $workspace = Workspace::factory()->create();
        $connection = Connection::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '999',
            'status' => 'active',
        ]);

        $result = app(UpsertCanonicalOrder::class)->executeWithMeta($workspace->id, $connection->id, [
            'external_order_id' => 'ORD-SOUND-3',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => '10',
            'lines' => [],
        ]);

        event(new OrderUpdated($result->order, isNew: $result->wasCreated));

        Event::assertDispatched(OrderUpdated::class, function (OrderUpdated $e) use ($result) {
            return $e->isNew === true
                && $e->order->is($result->order)
                && $e->broadcastWith()['is_new'] === true;
        });
    }
}
