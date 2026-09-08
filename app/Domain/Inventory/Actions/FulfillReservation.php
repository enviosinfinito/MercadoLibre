<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Outbound\Actions\EnqueueChannelStockSync;
use App\Models\InventoryBalance;
use App\Models\InventoryLedger;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class FulfillReservation
{
    public function __construct(
        private readonly EnqueueChannelStockSync $enqueueChannelStockSync,
    ) {}

    public function execute(Reservation $reservation, ?string $idempotencyKey = null): Reservation
    {
        if ($reservation->status !== 'active') {
            return $reservation;
        }

        $key = $idempotencyKey ?? ('fulfill:reservation:'.$reservation->id);

        $updated = DB::transaction(function () use ($reservation, $key) {
            /** @var Reservation $reservation */
            $reservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if ($reservation->status !== 'active') {
                return $reservation;
            }

            $existingLedger = InventoryLedger::query()
                ->where('workspace_id', $reservation->workspace_id)
                ->where('idempotency_key', $key.':ledger')
                ->first();
            if ($existingLedger) {
                return $reservation->fresh();
            }

            $balance = InventoryBalance::query()
                ->where('inventory_item_id', $reservation->inventory_item_id)
                ->lockForUpdate()
                ->first();

            if ($balance === null) {
                throw new RuntimeException('Inventory balance missing for reservation.');
            }

            $qty = (string) $reservation->quantity;
            $newReserved = bcsub((string) $balance->quantity_reserved, $qty, 6);
            if (bccomp($newReserved, '0', 6) < 0) {
                $newReserved = '0';
            }
            $newOnHand = bcsub((string) $balance->quantity_on_hand, $qty, 6);
            $newAvailable = bcsub($newOnHand, $newReserved, 6);

            $balance->quantity_reserved = $newReserved;
            $balance->quantity_on_hand = $newOnHand;
            $balance->quantity_available = $newAvailable;
            $balance->save();

            InventoryLedger::query()->create([
                'workspace_id' => $reservation->workspace_id,
                'inventory_item_id' => $reservation->inventory_item_id,
                'variant_id' => $reservation->variant_id,
                'warehouse_id' => $reservation->warehouse_id,
                'movement_type' => 'fulfill',
                'quantity_delta' => bcmul($qty, '-1', 6),
                'quantity_after' => $newAvailable,
                'reference_type' => Reservation::class,
                'reference_id' => $reservation->id,
                'idempotency_key' => $key.':ledger',
                'meta' => ['order_id' => $reservation->order_id],
                'occurred_at' => now(),
            ]);

            $reservation->status = 'fulfilled';
            $reservation->released_at = now();
            $reservation->save();

            return $reservation;
        });

        if ($updated->variant_id) {
            $this->enqueueChannelStockSync->execute(
                (int) $updated->workspace_id,
                (int) $updated->variant_id,
            );
        }

        return $updated;
    }

    public function fulfillActiveForOrder(int $workspaceId, int $orderId): int
    {
        $reservations = Reservation::query()
            ->where('workspace_id', $workspaceId)
            ->where('order_id', $orderId)
            ->where('status', 'active')
            ->get();

        $count = 0;
        foreach ($reservations as $reservation) {
            $this->execute($reservation);
            $count++;
        }

        return $count;
    }
}
