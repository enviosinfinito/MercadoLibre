<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Outbound\Actions\EnqueueChannelStockSync;
use App\Models\Connection;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLedger;
use App\Models\MarketplaceInbound;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ShipStockToFull
{
    public function __construct(
        private readonly ApplyInventoryDelta $applyDelta,
        private readonly EnqueueChannelStockSync $enqueueChannelStockSync,
    ) {}

    /**
     * @param  array{
     *   variant_id:int,
     *   connection_id:int,
     *   from_warehouse_id?:int|null,
     *   quantity:string|float|int,
     *   external_inbound_id?:string|null,
     *   notes?:string|null,
     *   idempotency_key?:string|null,
     *   sent_at?:string|null
     * }  $input
     * @return array{inbound: MarketplaceInbound, ledger: InventoryLedger}
     */
    public function execute(int $workspaceId, array $input): array
    {
        $qty = (string) $input['quantity'];
        if (bccomp($qty, '0', 6) <= 0) {
            throw new RuntimeException('quantity must be positive.');
        }

        $result = DB::transaction(function () use ($workspaceId, $input, $qty) {
            $connection = Connection::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey((int) $input['connection_id'])
                ->firstOrFail();

            $warehouseId = $input['from_warehouse_id'] ?? Warehouse::query()
                ->where('workspace_id', $workspaceId)
                ->where('is_default', true)
                ->value('id');
            if ($warehouseId === null) {
                throw new RuntimeException('No hay almacén origen para enviar a Full.');
            }

            $item = InventoryItem::query()->firstOrCreate([
                'workspace_id' => $workspaceId,
                'variant_id' => (int) $input['variant_id'],
                'warehouse_id' => (int) $warehouseId,
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
            if (bccomp((string) $balance->quantity_available, $qty, 6) < 0) {
                throw new RuntimeException('Stock disponible insuficiente para enviar a Full.');
            }

            $externalInboundId = isset($input['external_inbound_id'])
                ? trim((string) $input['external_inbound_id'])
                : '';
            $externalInboundId = $externalInboundId !== '' ? $externalInboundId : null;

            $idempotency = $input['idempotency_key'] ?? ('ship_to_full:'.uniqid('', true));

            $existingLedger = InventoryLedger::query()
                ->where('workspace_id', $workspaceId)
                ->where('idempotency_key', $idempotency)
                ->first();
            if ($existingLedger !== null && $existingLedger->reference_id !== null) {
                $existingInbound = MarketplaceInbound::query()->find($existingLedger->reference_id);
                if ($existingInbound !== null) {
                    return ['inbound' => $existingInbound, 'ledger' => $existingLedger];
                }
            }

            $inbound = MarketplaceInbound::query()->create([
                'workspace_id' => $workspaceId,
                'connection_id' => $connection->id,
                'variant_id' => (int) $input['variant_id'],
                'from_warehouse_id' => (int) $warehouseId,
                'qty_sent' => $qty,
                'qty_confirmed' => null,
                'external_inbound_id' => $externalInboundId,
                'full_stock_operation_id' => null,
                'status' => MarketplaceInbound::STATUS_PENDING,
                'sent_at' => $input['sent_at'] ?? now(),
                'matched_at' => null,
                'notes' => $input['notes'] ?? null,
            ]);

            $ledger = $this->applyDelta->execute(
                $workspaceId,
                (int) $input['variant_id'],
                (int) $warehouseId,
                bcmul($qty, '-1', 6),
                'ship_to_full',
                $idempotency,
                [
                    'marketplace_inbound_id' => $inbound->id,
                    'connection_id' => $connection->id,
                    'external_inbound_id' => $externalInboundId,
                    'notes' => $input['notes'] ?? null,
                ],
                MarketplaceInbound::class,
                (int) $inbound->id,
                $input['sent_at'] ?? now(),
            );

            return ['inbound' => $inbound, 'ledger' => $ledger];
        });

        $this->enqueueChannelStockSync->execute($workspaceId, (int) $input['variant_id']);

        return $result;
    }
}
