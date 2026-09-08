<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Support\FullStockOperationType;
use App\Models\FullStockOperation;
use App\Models\InventoryLedger;
use App\Models\MarketplaceInbound;
use App\Models\SupplierPurchaseOrderLine;
use Illuminate\Support\Carbon;

final class BuildVariantInventoryTimeline
{
    /**
     * @return list<array<string, mixed>>
     */
    public function execute(int $workspaceId, int $variantId): array
    {
        $events = collect();

        $lines = SupplierPurchaseOrderLine::query()
            ->where('workspace_id', $workspaceId)
            ->where('variant_id', $variantId)
            ->with('purchaseOrder:id,supplier_name,status,ordered_at')
            ->get();

        foreach ($lines as $line) {
            $po = $line->purchaseOrder;
            $ordered = (string) $line->qty_ordered;
            $received = (string) $line->qty_received;
            $events->push($this->event(
                step: 'A',
                source: 'purchase_order',
                occurredAt: $po?->ordered_at ?? $line->created_at,
                title: 'OC proveedor · '.$this->fmtQty($ordered).' pedidas',
                subtitle: ($po?->supplier_name ?? 'Proveedor').' · '.$this->fmtQty($received).' recibidas',
                quantity: $ordered,
                status: $po?->status,
                links: [
                    'purchase_order_id' => $po?->id,
                    'purchase_order_line_id' => $line->id,
                ],
            ));
        }

        $receives = InventoryLedger::query()
            ->where('workspace_id', $workspaceId)
            ->where('variant_id', $variantId)
            ->where('movement_type', 'receive')
            ->where(function ($q): void {
                $q->whereNull('idempotency_key')
                    ->orWhere('idempotency_key', 'not like', 'restock:%');
            })
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        foreach ($receives as $entry) {
            $meta = is_array($entry->meta) ? $entry->meta : [];
            $expected = isset($meta['qty_expected']) ? (string) $meta['qty_expected'] : null;
            $actual = (string) $entry->quantity_delta;
            $variance = $expected !== null ? bcsub($actual, $expected, 6) : null;
            $subtitle = $expected !== null
                ? 'Recibido '.$this->fmtQty($actual).' · var '.$this->fmtSigned($variance)
                : 'Recibido '.$this->fmtQty($actual);
            $events->push($this->event(
                step: 'B',
                source: 'ledger',
                occurredAt: $entry->occurred_at,
                title: 'Recepción en almacén',
                subtitle: $subtitle,
                quantity: $actual,
                status: $variance !== null && bccomp($variance, '0', 6) !== 0 ? 'discrepancy' : 'ok',
                links: [
                    'ledger_id' => $entry->id,
                    'purchase_order_line_id' => $meta['purchase_order_line_id'] ?? null,
                ],
            ));
        }

        $inbounds = MarketplaceInbound::query()
            ->where('workspace_id', $workspaceId)
            ->where('variant_id', $variantId)
            ->with('fullStockOperation:id,operation_type,external_operation_id,occurred_at,available_quantity_delta,external_references,marketplace_inbound_id')
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get();

        foreach ($inbounds as $inbound) {
            $events->push($this->event(
                step: 'C',
                source: 'marketplace_inbound',
                occurredAt: $inbound->sent_at ?? $inbound->created_at,
                title: 'Enviado a Full '.$this->fmtQty((string) $inbound->qty_sent),
                subtitle: $inbound->status.($inbound->external_inbound_id ? ' · inbound '.$inbound->external_inbound_id : ''),
                quantity: (string) $inbound->qty_sent,
                status: $inbound->status,
                links: [
                    'marketplace_inbound_id' => $inbound->id,
                    'full_operation_id' => $inbound->full_stock_operation_id,
                    'connection_id' => $inbound->connection_id,
                ],
            ));

            $matchedOp = $inbound->fullStockOperation;
            if ($matchedOp !== null) {
                $events->push($this->fullEvent($matchedOp, 'C.1', 'Confirmación Full (ingreso)'));
            }
        }

        $matchedInboundOpIds = $inbounds->pluck('full_stock_operation_id')->filter()->all();

        $fullOps = FullStockOperation::query()
            ->where('workspace_id', $workspaceId)
            ->where('variant_id', $variantId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        foreach ($fullOps as $op) {
            $type = (string) $op->operation_type;
            $family = FullStockOperationType::familyForType($type);

            if ($family === FullStockOperationType::FAMILY_INBOUND) {
                if (in_array($op->id, $matchedInboundOpIds, true)) {
                    continue;
                }
                $events->push($this->fullEvent($op, 'C.1', 'Confirmación Full (ingreso)'));

                continue;
            }

            if (in_array($family, [
                FullStockOperationType::FAMILY_SALE,
                FullStockOperationType::FAMILY_CANCELLATION,
            ], true)) {
                $events->push($this->fullEvent($op, 'D', FullStockOperationType::label($type)));

                continue;
            }

            if (in_array($family, [
                FullStockOperationType::FAMILY_RETURN,
                FullStockOperationType::FAMILY_WITHDRAWAL,
            ], true)) {
                $events->push($this->fullEvent($op, 'E', FullStockOperationType::label($type)));
            }
        }

        $returns = InventoryLedger::query()
            ->where('workspace_id', $workspaceId)
            ->where('variant_id', $variantId)
            ->whereIn('movement_type', ['return_from_full', 'ship_to_full'])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        foreach ($returns as $entry) {
            if ($entry->movement_type === 'ship_to_full') {
                continue;
            }
            $meta = is_array($entry->meta) ? $entry->meta : [];
            $isRestock = str_starts_with((string) $entry->idempotency_key, 'restock:');
            $events->push($this->event(
                step: 'E',
                source: 'ledger',
                occurredAt: $entry->occurred_at,
                title: $isRestock ? 'Restock postventa' : 'Devolución a tu almacén',
                subtitle: '+'.$this->fmtQty((string) $entry->quantity_delta),
                quantity: (string) $entry->quantity_delta,
                status: 'returned',
                links: [
                    'ledger_id' => $entry->id,
                    'full_operation_id' => $meta['full_operation_id'] ?? null,
                    'order_id' => $meta['order_id'] ?? null,
                    'marketplace_inbound_id' => $meta['marketplace_inbound_id'] ?? null,
                ],
            ));
        }

        $restocks = InventoryLedger::query()
            ->where('workspace_id', $workspaceId)
            ->where('variant_id', $variantId)
            ->where('movement_type', 'receive')
            ->where('idempotency_key', 'like', 'restock:%')
            ->get();

        foreach ($restocks as $entry) {
            $meta = is_array($entry->meta) ? $entry->meta : [];
            $events->push($this->event(
                step: 'E',
                source: 'ledger',
                occurredAt: $entry->occurred_at,
                title: 'Restock postventa',
                subtitle: 'Vuelta a tu almacén · +'.$this->fmtQty((string) $entry->quantity_delta),
                quantity: (string) $entry->quantity_delta,
                status: 'restocked',
                links: [
                    'ledger_id' => $entry->id,
                    'full_operation_id' => $meta['full_operation_id'] ?? null,
                    'order_id' => $meta['order_id'] ?? null,
                ],
            ));
        }

        return $events
            ->sortBy(function (array $event) {
                $ts = $event['occurred_at'] !== null
                    ? Carbon::parse($event['occurred_at'])->timestamp
                    : 0;

                return sprintf('%010d-%s-%s', $ts, $event['step'], $event['id'] ?? '');
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $links
     * @return array<string, mixed>
     */
    private function event(
        string $step,
        string $source,
        mixed $occurredAt,
        string $title,
        string $subtitle,
        ?string $quantity,
        ?string $status,
        array $links,
    ): array {
        return [
            'id' => $source.'-'.$step.'-'.md5($title.'|'.$subtitle.'|'.(string) $quantity.'|'.json_encode($links)),
            'step' => $step,
            'source' => $source,
            'occurred_at' => $occurredAt !== null
                ? Carbon::parse($occurredAt)->toIso8601String()
                : null,
            'title' => $title,
            'subtitle' => $subtitle,
            'quantity' => $quantity,
            'status' => $status,
            'links' => $links,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fullEvent(FullStockOperation $op, string $step, string $title): array
    {
        $delta = $op->available_quantity_delta !== null ? (string) $op->available_quantity_delta : null;
        $inboundId = $op->inboundIdFromReferences();

        return $this->event(
            step: $step,
            source: 'full_operation',
            occurredAt: $op->occurred_at,
            title: $title,
            subtitle: FullStockOperationType::label((string) $op->operation_type)
                .($inboundId ? ' · inbound '.$inboundId : '')
                .($delta !== null ? ' · Δ '.$this->fmtSigned($delta) : ''),
            quantity: $delta,
            status: $op->marketplace_inbound_id ? 'matched' : (string) $op->operation_type,
            links: [
                'full_operation_id' => $op->id,
                'marketplace_inbound_id' => $op->marketplace_inbound_id,
            ],
        );
    }

    private function fmtQty(?string $qty): string
    {
        if ($qty === null || $qty === '') {
            return '—';
        }

        return rtrim(rtrim(number_format((float) $qty, 2, '.', ''), '0'), '.') ?: '0';
    }

    private function fmtSigned(?string $qty): string
    {
        if ($qty === null || $qty === '') {
            return '—';
        }
        $formatted = $this->fmtQty($qty);
        if (bccomp($qty, '0', 6) > 0) {
            return '+'.$formatted;
        }

        return $formatted;
    }
}
