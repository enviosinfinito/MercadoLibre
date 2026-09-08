<?php

namespace App\Domain\Fulfillment\Actions;

use App\Domain\Inventory\Actions\FulfillReservation;
use App\Domain\Inventory\Actions\ReleaseStock;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class UpsertCanonicalShipment
{
    public function __construct(
        private readonly FulfillReservation $fulfillReservation,
        private readonly ReleaseStock $releaseStock,
    ) {}

    /**
     * @param  array{
     *   external_shipment_id: string,
     *   status?: string,
     *   carrier?: string|null,
     *   tracking_number?: string|null,
     *   shipped_at?: Carbon|string|null,
     *   delivered_at?: Carbon|string|null,
     *   order_id?: int|null,
     *   external_order_id?: string|null,
     *   meta?: array<string, mixed>|null,
     * }  $payload
     */
    public function execute(int $workspaceId, int $connectionId, array $payload): Shipment
    {
        $previousStatus = null;

        $shipment = DB::transaction(function () use ($workspaceId, $connectionId, $payload, &$previousStatus) {
            $canonicalStatus = $this->mapStatus((string) ($payload['status'] ?? 'pending'));
            $orderId = $payload['order_id'] ?? null;

            if ($orderId === null && isset($payload['external_order_id']) && $payload['external_order_id'] !== '') {
                $orderId = Order::query()
                    ->where('connection_id', $connectionId)
                    ->where('external_order_id', (string) $payload['external_order_id'])
                    ->value('id');
            }

            if ($orderId === null) {
                $orderId = $this->ordersSharingShipment(
                    $connectionId,
                    (string) $payload['external_shipment_id'],
                )[0] ?? null;
            }

            $existing = Shipment::query()
                ->where('connection_id', $connectionId)
                ->where('external_shipment_id', (string) $payload['external_shipment_id'])
                ->first();
            if ($existing) {
                $previousStatus = $existing->status;
            }

            $shipment = Shipment::query()->updateOrCreate(
                [
                    'connection_id' => $connectionId,
                    'external_shipment_id' => (string) $payload['external_shipment_id'],
                ],
                [
                    'workspace_id' => $workspaceId,
                    'order_id' => $orderId,
                    'status' => $canonicalStatus,
                    'carrier' => $payload['carrier'] ?? null,
                    'tracking_number' => $payload['tracking_number'] ?? null,
                    'shipped_at' => $payload['shipped_at'] ?? null,
                    'delivered_at' => $payload['delivered_at'] ?? null,
                    'meta' => $payload['meta'] ?? null,
                ],
            );

            // Pack carts share one shipment across multiple orders; mirror status to every sibling.
            $siblingOrderIds = $this->ordersSharingShipment(
                $connectionId,
                (string) $payload['external_shipment_id'],
            );

            if ($orderId !== null && ! in_array((int) $orderId, $siblingOrderIds, true)) {
                $siblingOrderIds[] = (int) $orderId;
            }

            foreach ($siblingOrderIds as $siblingOrderId) {
                $this->mirrorOrderStatus($siblingOrderId, $canonicalStatus);
            }

            return $shipment->fresh(['order']);
        });

        $siblingOrderIds = $this->ordersSharingShipment(
            $connectionId,
            (string) ($payload['external_shipment_id'] ?? $shipment->external_shipment_id),
        );

        if ($shipment->order_id !== null && ! in_array((int) $shipment->order_id, $siblingOrderIds, true)) {
            $siblingOrderIds[] = (int) $shipment->order_id;
        }

        $status = $shipment->status;

        if ($siblingOrderIds !== []) {
            if (in_array($status, ['shipped', 'delivered', 'in_transit'], true)
                && ! in_array($previousStatus, ['shipped', 'delivered', 'in_transit'], true)
            ) {
                foreach ($siblingOrderIds as $siblingOrderId) {
                    $this->fulfillReservation->fulfillActiveForOrder($workspaceId, $siblingOrderId);
                }
            }

            if ($status === 'cancelled' && $previousStatus !== 'cancelled') {
                foreach ($siblingOrderIds as $siblingOrderId) {
                    $this->releaseStock->releaseActiveForOrder($workspaceId, $siblingOrderId);
                }
            }
        }

        return $shipment;
    }

    /**
     * Extract a stable, UI-friendly subset of the Mercado Libre shipment payload.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function buildMetaFromProviderPayload(array $raw): array
    {
        $history = is_array($raw['status_history'] ?? null) ? $raw['status_history'] : null;
        $receiverAddress = is_array($raw['receiver_address'] ?? null) ? $raw['receiver_address'] : null;
        $shippingOption = is_array($raw['shipping_option'] ?? null) ? $raw['shipping_option'] : null;

        $meta = array_filter([
            'substatus' => isset($raw['substatus']) ? (string) $raw['substatus'] : null,
            'mode' => isset($raw['mode']) ? (string) $raw['mode'] : null,
            'logistic_type' => isset($raw['logistic_type']) ? (string) $raw['logistic_type'] : null,
            'tracking_method' => isset($raw['tracking_method']) ? (string) $raw['tracking_method'] : null,
            'service_id' => $raw['service_id'] ?? null,
            'receiver_id' => isset($raw['receiver_id']) ? (string) $raw['receiver_id'] : null,
            'date_created' => isset($raw['date_created']) ? (string) $raw['date_created'] : null,
            'last_updated' => isset($raw['last_updated']) ? (string) $raw['last_updated'] : null,
            'base_cost' => $raw['base_cost'] ?? null,
            'order_cost' => $raw['order_cost'] ?? null,
            'status_history' => $history,
            'receiver_address' => $receiverAddress,
            'shipping_option' => $shippingOption,
            'receiver' => is_array($raw['receiver'] ?? null) ? $raw['receiver'] : null,
        ], static fn ($value) => $value !== null && $value !== '');

        return $meta;
    }

    public function mapStatus(string $providerStatus): string
    {
        return match (strtolower($providerStatus)) {
            'delivered' => 'delivered',
            'shipped' => 'shipped',
            'in_transit', 'out_for_delivery' => 'in_transit',
            'ready_to_ship', 'handling' => 'ready_to_ship',
            'cancelled', 'canceled' => 'cancelled',
            'not_delivered' => 'not_delivered',
            default => strtolower($providerStatus) !== '' ? strtolower($providerStatus) : 'pending',
        };
    }

    private function mirrorOrderStatus(int $orderId, string $shipmentStatus): void
    {
        $mirrored = match ($shipmentStatus) {
            'delivered' => 'delivered',
            'shipped', 'in_transit' => 'shipped',
            default => null,
        };

        if ($mirrored === null) {
            return;
        }

        $order = Order::query()->find($orderId);
        if ($order === null) {
            return;
        }

        $rank = ['paid' => 1, 'shipped' => 2, 'delivered' => 3];
        $currentRank = $rank[$order->status] ?? 0;
        $nextRank = $rank[$mirrored] ?? 0;

        if ($nextRank > $currentRank) {
            $order->status = $mirrored;
            $order->save();
        }
    }

    /**
     * @return list<int>
     */
    private function ordersSharingShipment(int $connectionId, string $externalShipmentId): array
    {
        $query = Order::query()->where('connection_id', $connectionId);

        $query->where(function ($builder) use ($externalShipmentId) {
            $builder->where('meta->shipping->id', $externalShipmentId)
                ->orWhere('meta->shipping->id', (string) $externalShipmentId);

            if (ctype_digit($externalShipmentId)) {
                $builder->orWhere('meta->shipping->id', (int) $externalShipmentId);
            }
        });

        return $query->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
