<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Outbound\Actions\EnqueueChannelStockSync;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLedger;
use App\Models\OrderLine;
use App\Models\Reservation;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ReserveStock
{
    public function __construct(
        private readonly EnqueueChannelStockSync $enqueueChannelStockSync,
    ) {}

    public function execute(OrderLine $orderLine, ?int $warehouseId = null): ?Reservation
    {
        if ($orderLine->variant_id === null) {
            return null;
        }

        $idempotencyKey = 'reserve:order_line:'.$orderLine->id;

        $existing = Reservation::query()
            ->where('workspace_id', $orderLine->workspace_id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        $reservation = DB::transaction(function () use ($orderLine, $warehouseId, $idempotencyKey) {
            $warehouseId ??= Warehouse::query()
                ->where('workspace_id', $orderLine->workspace_id)
                ->where('is_default', true)
                ->value('id');

            if ($warehouseId === null) {
                $warehouseId = Warehouse::query()
                    ->where('workspace_id', $orderLine->workspace_id)
                    ->where('is_active', true)
                    ->value('id');
            }

            if ($warehouseId === null) {
                throw new RuntimeException('No warehouse available for reservation.');
            }

            $item = InventoryItem::query()->firstOrCreate(
                [
                    'workspace_id' => $orderLine->workspace_id,
                    'variant_id' => $orderLine->variant_id,
                    'warehouse_id' => $warehouseId,
                ],
            );

            $balance = InventoryBalance::query()->firstOrCreate(
                ['inventory_item_id' => $item->id],
                [
                    'workspace_id' => $orderLine->workspace_id,
                    'quantity_on_hand' => '0',
                    'quantity_reserved' => '0',
                    'quantity_available' => '0',
                ],
            );

            /** @var InventoryBalance $balance */
            $balance = InventoryBalance::query()
                ->whereKey($balance->id)
                ->lockForUpdate()
                ->firstOrFail();

            $qty = (string) $orderLine->quantity;
            $newReserved = bcadd((string) $balance->quantity_reserved, $qty, 6);
            $newAvailable = bcsub((string) $balance->quantity_on_hand, $newReserved, 6);

            $balance->quantity_reserved = $newReserved;
            $balance->quantity_available = $newAvailable;
            $balance->save();

            InventoryLedger::query()->create([
                'workspace_id' => $orderLine->workspace_id,
                'inventory_item_id' => $item->id,
                'variant_id' => $orderLine->variant_id,
                'warehouse_id' => $warehouseId,
                'movement_type' => 'reserve',
                'quantity_delta' => bcmul($qty, '-1', 6),
                'quantity_after' => $newAvailable,
                'reference_type' => OrderLine::class,
                'reference_id' => $orderLine->id,
                'idempotency_key' => $idempotencyKey.':ledger',
                'meta' => ['order_id' => $orderLine->order_id],
                'occurred_at' => now(),
            ]);

            return Reservation::query()->create([
                'workspace_id' => $orderLine->workspace_id,
                'inventory_item_id' => $item->id,
                'variant_id' => $orderLine->variant_id,
                'warehouse_id' => $warehouseId,
                'order_id' => $orderLine->order_id,
                'order_line_id' => $orderLine->id,
                'quantity' => $qty,
                'status' => 'active',
                'idempotency_key' => $idempotencyKey,
                'reserved_at' => now(),
            ]);
        });

        $this->enqueueChannelStockSync->execute(
            (int) $orderLine->workspace_id,
            (int) $orderLine->variant_id,
        );

        return $reservation;
    }
}
