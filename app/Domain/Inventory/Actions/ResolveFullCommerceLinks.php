<?php

namespace App\Domain\Inventory\Actions;

use App\Models\FullStockOperation;
use App\Models\Order;
use App\Models\ReturnCase;
use App\Models\Shipment;
use Illuminate\Support\Collection;

final class ResolveFullCommerceLinks
{
    /**
     * Resolve order / shipment / return from Full operation external_references.
     *
     * @param  list<array<string, mixed>>|array<int, mixed>  $externalReferences
     * @return array{
     *   order: ?Order,
     *   shipment: ?Shipment,
     *   return_case: ?ReturnCase,
     *   match_via: ?string,
     *   ref_values: list<string>
     * }
     */
    public function execute(int $workspaceId, ?int $connectionId, array $externalReferences): array
    {
        $refValues = $this->extractRefValues($externalReferences);

        $empty = [
            'order' => null,
            'shipment' => null,
            'return_case' => null,
            'match_via' => null,
            'ref_values' => $refValues,
        ];

        if ($refValues === []) {
            return $empty;
        }

        $order = Order::query()
            ->where('workspace_id', $workspaceId)
            ->when($connectionId !== null, fn ($q) => $q->where('connection_id', $connectionId))
            ->whereIn('external_order_id', $refValues)
            ->first();

        $matchVia = $order !== null ? 'order_external_id' : null;

        $shipment = Shipment::query()
            ->where('workspace_id', $workspaceId)
            ->when($connectionId !== null, fn ($q) => $q->where('connection_id', $connectionId))
            ->whereIn('external_shipment_id', $refValues)
            ->first();

        if ($shipment !== null && $matchVia === null) {
            $matchVia = 'shipment_external_id';
        }

        if ($order === null && $shipment?->order_id) {
            $order = Order::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($shipment->order_id)
                ->first();
        }

        // If we matched order by external id but shipment is still null, keep any
        // canonical shipment for that order that also appears in refs (rare).
        if ($shipment === null && $order !== null) {
            $shipment = Shipment::query()
                ->where('workspace_id', $workspaceId)
                ->where('order_id', $order->id)
                ->whereIn('external_shipment_id', $refValues)
                ->first();
        }

        $returnCase = null;
        if ($order !== null) {
            $returnCase = ReturnCase::query()
                ->where('workspace_id', $workspaceId)
                ->where('order_id', $order->id)
                ->orderByDesc('opened_at')
                ->orderByDesc('id')
                ->first();
        }

        return [
            'order' => $order,
            'shipment' => $shipment,
            'return_case' => $returnCase,
            'match_via' => $matchVia,
            'ref_values' => $refValues,
        ];
    }

    /**
     * Find Full operations whose refs contain any of the given commerce ids.
     *
     * @param  list<string>  $commerceIds
     * @return Collection<int, FullStockOperation>
     */
    public function findOperationsForCommerceIds(
        int $workspaceId,
        ?int $connectionId,
        array $commerceIds,
        int $limit = 50,
    ): Collection {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($v) => trim((string) $v),
            $commerceIds,
        ), static fn (string $v) => $v !== '')));

        if ($ids === []) {
            return collect();
        }

        $query = FullStockOperation::query()
            ->where('workspace_id', $workspaceId)
            ->when($connectionId !== null, fn ($q) => $q->where('connection_id', $connectionId))
            ->where(function ($outer) use ($ids): void {
                foreach ($ids as $id) {
                    $outer->orWhere('external_references', 'like', '%'.$id.'%');
                }
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit);

        return $query->get();
    }

    /**
     * Commerce ids that can appear in Full operation external_references.
     *
     * @return list<string>
     */
    public function commerceIdsForOrder(Order $order): array
    {
        $ids = [];

        if (is_string($order->external_order_id) && $order->external_order_id !== '') {
            $ids[] = $order->external_order_id;
        }

        $metaShippingId = is_array($order->meta) ? ($order->meta['shipping']['id'] ?? null) : null;
        if ($metaShippingId !== null && $metaShippingId !== '') {
            $ids[] = (string) $metaShippingId;
        }

        $shipmentIds = Shipment::query()
            ->where('workspace_id', $order->workspace_id)
            ->where('order_id', $order->id)
            ->whereNotNull('external_shipment_id')
            ->pluck('external_shipment_id')
            ->map(fn ($id) => trim((string) $id))
            ->filter(fn (string $id) => $id !== '')
            ->all();

        return array_values(array_unique(array_merge($ids, $shipmentIds)));
    }

    /**
     * @param  list<array<string, mixed>>|array<int, mixed>  $externalReferences
     * @return list<string>
     */
    public function extractRefValues(array $externalReferences): array
    {
        $values = [];
        foreach ($externalReferences as $ref) {
            if (! is_array($ref)) {
                continue;
            }
            $value = $ref['value'] ?? $ref['id'] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $values[] = (string) $value;
        }

        return array_values(array_unique($values));
    }
}
