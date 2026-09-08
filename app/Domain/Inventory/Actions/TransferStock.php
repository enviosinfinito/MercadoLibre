<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Outbound\Actions\EnqueueChannelStockSync;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLedger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class TransferStock
{
    public function __construct(
        private readonly EnqueueChannelStockSync $enqueueChannelStockSync,
    ) {}

    /**
     * @param  array{
     *   variant_id:int,
     *   from_warehouse_id:int,
     *   to_warehouse_id:int,
     *   quantity:string|float|int,
     *   notes?:string|null,
     *   idempotency_key?:string|null
     * }  $input
     * @return array{out: InventoryLedger, in: InventoryLedger}
     */
    public function execute(int $workspaceId, array $input): array
    {
        $fromId = (int) $input['from_warehouse_id'];
        $toId = (int) $input['to_warehouse_id'];
        if ($fromId === $toId) {
            throw new RuntimeException('from_warehouse_id and to_warehouse_id must differ.');
        }

        $qty = (string) $input['quantity'];
        if (bccomp($qty, '0', 6) <= 0) {
            throw new RuntimeException('quantity must be positive.');
        }

        $baseKey = $input['idempotency_key'] ?? ('transfer:'.uniqid('', true));

        $result = DB::transaction(function () use ($workspaceId, $input, $fromId, $toId, $qty, $baseKey) {
            $existingOut = InventoryLedger::query()
                ->where('workspace_id', $workspaceId)
                ->where('idempotency_key', $baseKey.':out')
                ->first();
            if ($existingOut) {
                $existingIn = InventoryLedger::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('idempotency_key', $baseKey.':in')
                    ->firstOrFail();

                return ['out' => $existingOut, 'in' => $existingIn];
            }

            $out = $this->applyDelta(
                $workspaceId,
                (int) $input['variant_id'],
                $fromId,
                bcmul($qty, '-1', 6),
                'transfer_out',
                $baseKey.':out',
                ['to_warehouse_id' => $toId, 'notes' => $input['notes'] ?? null],
            );

            $in = $this->applyDelta(
                $workspaceId,
                (int) $input['variant_id'],
                $toId,
                $qty,
                'transfer_in',
                $baseKey.':in',
                ['from_warehouse_id' => $fromId, 'notes' => $input['notes'] ?? null],
            );

            return ['out' => $out, 'in' => $in];
        });

        $this->enqueueChannelStockSync->execute($workspaceId, (int) $input['variant_id']);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function applyDelta(
        int $workspaceId,
        int $variantId,
        int $warehouseId,
        string $delta,
        string $movementType,
        string $idempotency,
        array $meta,
    ): InventoryLedger {
        $item = InventoryItem::query()->firstOrCreate([
            'workspace_id' => $workspaceId,
            'variant_id' => $variantId,
            'warehouse_id' => $warehouseId,
        ]);

        $balance = InventoryBalance::query()->firstOrCreate(
            ['inventory_item_id' => $item->id],
            [
                'workspace_id' => $workspaceId,
                'quantity_on_hand' => '0',
                'quantity_reserved' => '0',
                'quantity_available' => '0',
            ],
        );

        /** @var InventoryBalance $balance */
        $balance = InventoryBalance::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();

        $onHand = bcadd((string) $balance->quantity_on_hand, $delta, 6);
        $available = bcsub($onHand, (string) $balance->quantity_reserved, 6);
        $balance->quantity_on_hand = $onHand;
        $balance->quantity_available = $available;
        $balance->save();

        return InventoryLedger::query()->create([
            'workspace_id' => $workspaceId,
            'inventory_item_id' => $item->id,
            'variant_id' => $variantId,
            'warehouse_id' => $warehouseId,
            'movement_type' => $movementType,
            'quantity_delta' => $delta,
            'quantity_after' => $available,
            'reference_type' => null,
            'reference_id' => null,
            'idempotency_key' => $idempotency,
            'meta' => $meta,
            'occurred_at' => now(),
        ]);
    }
}
