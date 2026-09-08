<?php

namespace App\Domain\Inventory\Actions;

use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLedger;

final class ApplyInventoryDelta
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function execute(
        int $workspaceId,
        int $variantId,
        int $warehouseId,
        string $delta,
        string $movementType,
        string $idempotency,
        array $meta = [],
        ?string $referenceType = null,
        ?int $referenceId = null,
        mixed $occurredAt = null,
    ): InventoryLedger {
        $existing = InventoryLedger::query()
            ->where('workspace_id', $workspaceId)
            ->where('idempotency_key', $idempotency)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

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
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'idempotency_key' => $idempotency,
            'meta' => $meta,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }
}
