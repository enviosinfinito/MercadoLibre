<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Outbound\Actions\EnqueueChannelStockSync;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLedger;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AdjustStock
{
    public function __construct(
        private readonly EnqueueChannelStockSync $enqueueChannelStockSync,
    ) {}

    /**
     * @param  array{
     *   variant_id:int,
     *   warehouse_id?:int|null,
     *   quantity_delta:string|float|int,
     *   notes?:string|null,
     *   idempotency_key?:string|null
     * }  $input
     */
    public function execute(int $workspaceId, array $input): InventoryLedger
    {
        $ledger = DB::transaction(function () use ($workspaceId, $input) {
            $warehouseId = $input['warehouse_id'] ?? Warehouse::query()
                ->where('workspace_id', $workspaceId)
                ->where('is_default', true)
                ->value('id');

            if ($warehouseId === null) {
                throw new RuntimeException('No warehouse available for adjust.');
            }

            $delta = (string) $input['quantity_delta'];
            if (bccomp($delta, '0', 6) === 0) {
                throw new RuntimeException('quantity_delta must be non-zero.');
            }

            $idempotency = $input['idempotency_key'] ?? ('adjust:'.uniqid('', true));

            $existing = InventoryLedger::query()
                ->where('workspace_id', $workspaceId)
                ->where('idempotency_key', $idempotency)
                ->first();
            if ($existing) {
                return $existing;
            }

            $item = InventoryItem::query()->firstOrCreate([
                'workspace_id' => $workspaceId,
                'variant_id' => $input['variant_id'],
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
                'variant_id' => $input['variant_id'],
                'warehouse_id' => $warehouseId,
                'movement_type' => 'adjust',
                'quantity_delta' => $delta,
                'quantity_after' => $available,
                'reference_type' => null,
                'reference_id' => null,
                'idempotency_key' => $idempotency,
                'meta' => ['notes' => $input['notes'] ?? null],
                'occurred_at' => now(),
            ]);
        });

        $this->enqueueChannelStockSync->execute($workspaceId, (int) $input['variant_id']);

        return $ledger;
    }
}
