<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Outbound\Actions\EnqueueChannelStockSync;
use App\Models\CostLayer;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLedger;
use App\Models\SupplierPurchaseOrderLine;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ReceiveInventory
{
    public function __construct(
        private readonly EnqueueChannelStockSync $enqueueChannelStockSync,
    ) {}

    /**
     * @param  array{
     *   variant_id:int,
     *   quantity:string|float|int,
     *   unit_cost_amount:string|float|int,
     *   unit_cost_currency:string,
     *   warehouse_id?:int|null,
     *   fx_rate?:string|float|null,
     *   fx_from?:string|null,
     *   fx_to?:string|null,
     *   fx_source?:string|null,
     *   reporting_currency?:string,
     *   notes?:string|null,
     *   received_at?:string|null,
     *   idempotency_key?:string|null,
     *   purchase_order_line_id?:int|null,
     *   qty_expected?:string|float|int|null,
     *   meta?:array<string, mixed>
     * }  $input
     */
    public function execute(int $workspaceId, array $input): CostLayer
    {
        $idempotency = $input['idempotency_key'] ?? null;
        if (is_string($idempotency) && $idempotency !== '') {
            $existingLedger = InventoryLedger::query()
                ->where('workspace_id', $workspaceId)
                ->where('idempotency_key', $idempotency)
                ->first();
            if ($existingLedger !== null && $existingLedger->reference_id !== null) {
                $existingLayer = CostLayer::query()->find($existingLedger->reference_id);
                if ($existingLayer !== null) {
                    return $existingLayer;
                }
            }
        }

        $layer = DB::transaction(function () use ($workspaceId, $input) {
            $warehouseId = $input['warehouse_id'] ?? Warehouse::query()
                ->where('workspace_id', $workspaceId)
                ->where('is_default', true)
                ->value('id');

            $qty = (string) $input['quantity'];
            $unitCost = (string) $input['unit_cost_amount'];
            $currency = strtoupper((string) $input['unit_cost_currency']);
            $reportingCurrency = strtoupper((string) ($input['reporting_currency'] ?? $currency));
            $fxRate = isset($input['fx_rate']) && $input['fx_rate'] !== null
                ? (string) $input['fx_rate']
                : ($currency === $reportingCurrency ? '1' : null);

            if ($fxRate === null) {
                $fxRate = '1';
            }

            $unitCostReporting = bcmul($unitCost, $fxRate, 6);

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

            $idempotency = $input['idempotency_key'] ?? ('receive:'.uniqid('', true));

            $existing = InventoryLedger::query()
                ->where('workspace_id', $workspaceId)
                ->where('idempotency_key', $idempotency)
                ->first();
            if ($existing !== null && $existing->reference_id !== null) {
                $layer = CostLayer::query()->find($existing->reference_id);
                if ($layer !== null) {
                    return $layer;
                }
            }

            $poLine = null;
            $qtyExpected = isset($input['qty_expected']) && $input['qty_expected'] !== null
                ? (string) $input['qty_expected']
                : null;
            if (! empty($input['purchase_order_line_id'])) {
                $poLine = SupplierPurchaseOrderLine::query()
                    ->where('workspace_id', $workspaceId)
                    ->whereKey((int) $input['purchase_order_line_id'])
                    ->lockForUpdate()
                    ->first();
                if ($poLine === null) {
                    throw new RuntimeException('Línea de orden de compra no encontrada.');
                }
                if ((int) $poLine->variant_id !== (int) $input['variant_id']) {
                    throw new RuntimeException('La línea de OC no corresponde a esta variante.');
                }
                if ($qtyExpected === null) {
                    $qtyExpected = (string) $poLine->qty_ordered;
                }
            }

            $onHand = bcadd((string) $balance->quantity_on_hand, $qty, 6);
            $available = bcsub($onHand, (string) $balance->quantity_reserved, 6);
            $balance->quantity_on_hand = $onHand;
            $balance->quantity_available = $available;
            $balance->save();

            $discrepancy = $qtyExpected !== null ? bcsub($qty, $qtyExpected, 6) : null;
            $extraMeta = is_array($input['meta'] ?? null) ? $input['meta'] : [];

            InventoryLedger::query()->create([
                'workspace_id' => $workspaceId,
                'inventory_item_id' => $item->id,
                'variant_id' => $input['variant_id'],
                'warehouse_id' => $warehouseId,
                'movement_type' => 'receive',
                'quantity_delta' => $qty,
                'quantity_after' => $available,
                'reference_type' => CostLayer::class,
                'reference_id' => null,
                'idempotency_key' => $idempotency,
                'meta' => array_filter([
                    'unit_cost' => $unitCost,
                    'currency' => $currency,
                    'purchase_order_line_id' => $poLine?->id,
                    'qty_expected' => $qtyExpected,
                    'discrepancy' => $discrepancy,
                    ...$extraMeta,
                ], static fn ($value) => $value !== null && $value !== ''),
                'occurred_at' => $input['received_at'] ?? now(),
            ]);

            $layer = CostLayer::query()->create([
                'workspace_id' => $workspaceId,
                'variant_id' => $input['variant_id'],
                'warehouse_id' => $warehouseId,
                'qty_original' => $qty,
                'qty_remaining' => $qty,
                'qty_expected' => $qtyExpected,
                'unit_cost_amount' => $unitCost,
                'unit_cost_currency' => $currency,
                'fx_rate' => $fxRate,
                'fx_from' => $input['fx_from'] ?? $currency,
                'fx_to' => $input['fx_to'] ?? $reportingCurrency,
                'fx_source' => $input['fx_source'] ?? 'manual',
                'fx_dated_at' => now(),
                'unit_cost_reporting_amount' => $unitCostReporting,
                'reporting_currency' => $reportingCurrency,
                'source_type' => 'receipt',
                'purchase_order_line_id' => $poLine?->id,
                'notes' => $input['notes'] ?? null,
                'received_at' => $input['received_at'] ?? now(),
            ]);

            InventoryLedger::query()
                ->where('workspace_id', $workspaceId)
                ->where('idempotency_key', $idempotency)
                ->update(['reference_id' => $layer->id]);

            if ($poLine !== null) {
                $poLine->qty_received = bcadd((string) $poLine->qty_received, $qty, 6);
                $poLine->save();
                $poLine->purchaseOrder?->refreshStatusFromLines();
            }

            return $layer;
        });

        $this->enqueueChannelStockSync->execute($workspaceId, (int) $input['variant_id']);

        return $layer;
    }
}
