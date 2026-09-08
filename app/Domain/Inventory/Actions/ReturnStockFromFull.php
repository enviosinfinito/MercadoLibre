<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Outbound\Actions\EnqueueChannelStockSync;
use App\Models\FullStockOperation;
use App\Models\InventoryLedger;
use App\Models\MarketplaceInbound;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ReturnStockFromFull
{
    public function __construct(
        private readonly ApplyInventoryDelta $applyDelta,
        private readonly EnqueueChannelStockSync $enqueueChannelStockSync,
    ) {}

    /**
     * @param  array{
     *   variant_id:int,
     *   warehouse_id?:int|null,
     *   quantity:string|float|int,
     *   full_stock_operation_id?:int|null,
     *   marketplace_inbound_id?:int|null,
     *   notes?:string|null,
     *   idempotency_key?:string|null,
     *   occurred_at?:string|null
     * }  $input
     */
    public function execute(int $workspaceId, array $input): InventoryLedger
    {
        $qty = (string) $input['quantity'];
        if (bccomp($qty, '0', 6) <= 0) {
            throw new RuntimeException('quantity must be positive.');
        }

        $ledger = DB::transaction(function () use ($workspaceId, $input, $qty) {
            $warehouseId = $input['warehouse_id'] ?? Warehouse::query()
                ->where('workspace_id', $workspaceId)
                ->where('is_default', true)
                ->value('id');
            if ($warehouseId === null) {
                throw new RuntimeException('No hay almacén destino para devolver de Full.');
            }

            $fullOpId = isset($input['full_stock_operation_id']) ? (int) $input['full_stock_operation_id'] : null;
            $inboundId = isset($input['marketplace_inbound_id']) ? (int) $input['marketplace_inbound_id'] : null;
            $idempotency = $input['idempotency_key']
                ?? ($fullOpId !== null
                    ? 'return_from_full:full_op:'.$fullOpId
                    : 'return_from_full:'.uniqid('', true));

            $fullOp = null;
            if ($fullOpId !== null) {
                $fullOp = FullStockOperation::query()
                    ->where('workspace_id', $workspaceId)
                    ->whereKey($fullOpId)
                    ->firstOrFail();
            }

            $inbound = null;
            if ($inboundId !== null) {
                $inbound = MarketplaceInbound::query()
                    ->where('workspace_id', $workspaceId)
                    ->whereKey($inboundId)
                    ->firstOrFail();
            }

            return $this->applyDelta->execute(
                $workspaceId,
                (int) $input['variant_id'],
                (int) $warehouseId,
                $qty,
                'return_from_full',
                $idempotency,
                [
                    'full_operation_id' => $fullOp?->id,
                    'marketplace_inbound_id' => $inbound?->id,
                    'operation_type' => $fullOp?->operation_type,
                    'notes' => $input['notes'] ?? null,
                ],
                $fullOp !== null ? FullStockOperation::class : ($inbound !== null ? MarketplaceInbound::class : null),
                $fullOp?->id ?? $inbound?->id,
                $input['occurred_at'] ?? $fullOp?->occurred_at ?? now(),
            );
        });

        $this->enqueueChannelStockSync->execute($workspaceId, (int) $input['variant_id']);

        return $ledger;
    }

    /**
     * Auto-apply when Full reports a withdrawal delivered back to the seller.
     */
    public function maybeApplyFromFullOperation(FullStockOperation $operation): ?InventoryLedger
    {
        if (! in_array($operation->operation_type, ['WITHDRAWAL_DELIVERY', 'WITHDRAWAL_DISCARDED'], true)) {
            return null;
        }

        if ($operation->variant_id === null) {
            return null;
        }

        $qty = $this->positiveQty((string) ($operation->available_quantity_delta ?? '0'));
        if ($qty === null) {
            $qty = $this->positiveQty((string) ($operation->not_available_quantity_delta ?? '0'));
        }
        if ($qty === null) {
            return null;
        }

        $warehouseId = Warehouse::query()
            ->where('workspace_id', $operation->workspace_id)
            ->where('is_default', true)
            ->value('id');
        if ($warehouseId === null) {
            return null;
        }

        $key = 'return_from_full:full_op:'.$operation->id;
        $existing = InventoryLedger::query()
            ->where('workspace_id', $operation->workspace_id)
            ->where('idempotency_key', $key)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        return $this->execute((int) $operation->workspace_id, [
            'variant_id' => (int) $operation->variant_id,
            'warehouse_id' => (int) $warehouseId,
            'quantity' => $qty,
            'full_stock_operation_id' => (int) $operation->id,
            'idempotency_key' => $key,
            'occurred_at' => optional($operation->occurred_at)?->toIso8601String(),
            'notes' => 'Retiro Full '.$operation->operation_type,
        ]);
    }

    private function positiveQty(string $delta): ?string
    {
        if (bccomp($delta, '0', 6) === 0) {
            return null;
        }

        if (bccomp($delta, '0', 6) < 0) {
            return bcmul($delta, '-1', 6);
        }

        return $delta;
    }
}
