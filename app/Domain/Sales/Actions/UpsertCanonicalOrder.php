<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Support\UpsertCanonicalOrderResult;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Pack;
use Illuminate\Support\Facades\DB;

final class UpsertCanonicalOrder
{
    public function __construct(
        private readonly ResolveOrderLineCatalogMatch $resolveCatalogMatch,
    ) {}

    /**
     * @param  array{
     *   external_order_id:string,
     *   status?:string,
     *   buyer_external_id?:string|null,
     *   currency_code?:string,
     *   total_amount?:string|float|int,
     *   ordered_at?:string|null,
     *   paid_at?:string|null,
     *   cancelled_at?:string|null,
     *   raw_snapshot_id?:int|null,
     *   pack_id?:int|null,
     *   external_pack_id?:string|null,
     *   meta?:array|null,
     *   lines?:array<int, array{
     *     external_item_id?:string|null,
     *     external_variation_id?:string|null,
     *     sku?:string|null,
     *     title?:string|null,
     *     quantity?:string|float|int,
     *     unit_price_amount?:string|float|int,
     *     currency_code?:string,
     *     line_total_amount?:string|float|int
     *   }>
     * }  $payload
     */
    public function execute(int $workspaceId, int $connectionId, array $payload): Order
    {
        return $this->executeWithMeta($workspaceId, $connectionId, $payload)->order;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function executeWithMeta(int $workspaceId, int $connectionId, array $payload): UpsertCanonicalOrderResult
    {
        return DB::transaction(function () use ($workspaceId, $connectionId, $payload) {
            $incomingStatus = (string) ($payload['status'] ?? 'pending');

            $existing = Order::query()
                ->where('connection_id', $connectionId)
                ->where('external_order_id', (string) $payload['external_order_id'])
                ->first();
            $wasCreated = $existing === null;

            // Shipment sync may have advanced status beyond ML order.status (often stays "paid").
            if ($existing !== null
                && in_array($existing->status, ['shipped', 'delivered'], true)
                && in_array($incomingStatus, ['paid', 'confirmed', 'pending', 'payment_required', 'partially_paid'], true)
            ) {
                $incomingStatus = $existing->status;
            }

            $packId = $payload['pack_id'] ?? null;
            $externalPackId = isset($payload['external_pack_id']) && $payload['external_pack_id'] !== ''
                ? (string) $payload['external_pack_id']
                : null;

            if ($packId === null && $externalPackId !== null) {
                $pack = Pack::query()->updateOrCreate(
                    [
                        'connection_id' => $connectionId,
                        'external_pack_id' => $externalPackId,
                    ],
                    [
                        'workspace_id' => $workspaceId,
                        'status' => 'open',
                    ],
                );
                $packId = $pack->id;
            }

            $order = Order::query()->updateOrCreate(
                [
                    'connection_id' => $connectionId,
                    'external_order_id' => (string) $payload['external_order_id'],
                ],
                [
                    'workspace_id' => $workspaceId,
                    'pack_id' => $packId,
                    'status' => $incomingStatus,
                    'buyer_external_id' => $payload['buyer_external_id'] ?? null,
                    'currency_code' => $payload['currency_code'] ?? 'MXN',
                    'total_amount' => (string) ($payload['total_amount'] ?? '0'),
                    'ordered_at' => $payload['ordered_at'] ?? null,
                    'paid_at' => $payload['paid_at'] ?? null,
                    'cancelled_at' => $payload['cancelled_at'] ?? null,
                    'raw_snapshot_id' => $payload['raw_snapshot_id'] ?? null,
                    'meta' => $payload['meta'] ?? null,
                ],
            );

            $lines = $payload['lines'] ?? [];
            $keptIds = [];

            foreach ($lines as $line) {
                $sku = isset($line['sku']) && $line['sku'] !== '' ? (string) $line['sku'] : null;
                $match = $this->resolveCatalogMatch->execute(
                    $workspaceId,
                    $connectionId,
                    $sku,
                    isset($line['external_item_id']) ? (string) $line['external_item_id'] : null,
                    isset($line['external_variation_id']) ? (string) $line['external_variation_id'] : null,
                );

                $orderLine = OrderLine::query()->updateOrCreate(
                    [
                        'order_id' => $order->id,
                        'external_item_id' => $line['external_item_id'] ?? null,
                        'external_variation_id' => $line['external_variation_id'] ?? null,
                        'sku' => $sku,
                    ],
                    [
                        'workspace_id' => $workspaceId,
                        'connection_id' => $connectionId,
                        'variant_id' => $match['variant_id'],
                        'channel_listing_variant_id' => $match['channel_listing_variant_id'],
                        'title' => $line['title'] ?? null,
                        'quantity' => (string) ($line['quantity'] ?? '1'),
                        'unit_price_amount' => (string) ($line['unit_price_amount'] ?? '0'),
                        'currency_code' => $line['currency_code'] ?? ($payload['currency_code'] ?? 'MXN'),
                        'line_total_amount' => (string) ($line['line_total_amount'] ?? '0'),
                        'match_status' => $match['match_status'],
                    ],
                );

                $keptIds[] = $orderLine->id;
            }

            if ($keptIds !== []) {
                OrderLine::query()
                    ->where('order_id', $order->id)
                    ->whereNotIn('id', $keptIds)
                    ->delete();
            }

            return new UpsertCanonicalOrderResult(
                order: $order->fresh(['lines']),
                wasCreated: $wasCreated,
            );
        });
    }
}
