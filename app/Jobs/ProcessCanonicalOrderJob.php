<?php

namespace App\Jobs;

use App\Domain\Cash\Actions\SyncMarketplacePaymentForOrder;
use App\Domain\Finance\Actions\CalculateExpectedProfit;
use App\Domain\Finance\Actions\PromoteFinancialEventsToRealized;
use App\Domain\Finance\Actions\RecordExpectedFinancialEvents;
use App\Domain\Integrations\Actions\ResolveSyncProfile;
use App\Domain\Inventory\Actions\ReleaseStock;
use App\Domain\Inventory\Actions\ReserveStock;
use App\Domain\Inventory\Actions\ResolveFullCommerceLinks;
use App\Domain\Inventory\Support\FullStockOperationType;
use App\Domain\PostSale\Actions\LinkOrphanClaimsToOrder;
use App\Domain\Sales\Actions\UpsertCanonicalOrder;
use App\Domain\Sales\Support\BuyerPresentation;
use App\Domain\Sales\Support\OrderProviderDates;
use App\Domain\Shared\Support\BusinessDay;
use App\Events\OrderUpdated;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\ChannelListing;
use App\Models\Connection;
use App\Models\FullStockOperation;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OutboxEvent;
use App\Models\RawResourceSnapshot;
use Illuminate\Support\Facades\Bus;

final class ProcessCanonicalOrderJob extends TenantAwareJob
{
    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly int $snapshotId,
        public readonly bool $applyInventoryEffects = true,
        public readonly bool $projectSynchronously = false,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('critical-sync');
    }

    protected function handleForTenant(): void
    {
        $snapshot = RawResourceSnapshot::query()->findOrFail($this->snapshotId);
        $payload = $snapshot->payload;
        $connection = Connection::query()->findOrFail($this->connectionId);
        $resolve = app(ResolveSyncProfile::class);

        if (! $resolve->isEnabled($connection, 'orders')) {
            return;
        }

        $orderInclude = $resolve->execute($connection, 'orders')->config['include'] ?? [];
        $includeLines = (bool) ($orderInclude['lines'] ?? true);
        $includeBuyer = (bool) ($orderInclude['buyer'] ?? true);
        $includeShipping = (bool) ($orderInclude['shipping'] ?? false);

        $lines = [];
        $lineSaleFees = [];
        if ($includeLines) {
            foreach ($payload['order_items'] ?? $payload['lines'] ?? [] as $item) {
                $itemData = $item['item'] ?? $item;
                $sku = $itemData['seller_sku'] ?? $itemData['sku'] ?? $item['seller_sku'] ?? null;
                $unitPrice = $item['unit_price'] ?? $itemData['unit_price'] ?? 0;
                $qty = $item['quantity'] ?? 1;
                $currency = $payload['currency_id'] ?? $item['currency_id'] ?? 'MXN';
                $externalItemId = isset($itemData['id']) ? (string) $itemData['id'] : null;

                $lines[] = [
                    'external_item_id' => $externalItemId,
                    'external_variation_id' => isset($itemData['variation_id']) ? (string) $itemData['variation_id'] : null,
                    'sku' => $sku,
                    'title' => $itemData['title'] ?? null,
                    'quantity' => $qty,
                    'unit_price_amount' => $unitPrice,
                    'currency_code' => $currency,
                    'line_total_amount' => isset($item['full_unit_price'])
                        ? bcmul((string) $item['full_unit_price'], (string) $qty, 6)
                        : bcmul((string) $unitPrice, (string) $qty, 6),
                ];

                if ($externalItemId !== null && isset($item['sale_fee'])) {
                    $lineSaleFees[] = [
                        'external_item_id' => $externalItemId,
                        'sale_fee' => (string) $item['sale_fee'],
                    ];
                }
            }
        }

        $meta = ['provider_status' => $payload['status'] ?? null];
        if ($lineSaleFees !== []) {
            $meta['line_sale_fees'] = $lineSaleFees;
        }
        // Always keep shipping id for later cost enrichment (GET /shipments/{id}).
        if (isset($payload['shipping']) && is_array($payload['shipping'])) {
            $shippingId = $payload['shipping']['id'] ?? null;
            $meta['shipping'] = $includeShipping
                ? $payload['shipping']
                : array_filter([
                    'id' => $shippingId !== null && $shippingId !== '' ? (string) $shippingId : null,
                ], fn ($v) => $v !== null);
            if ($includeShipping && isset($meta['shipping']['id'])) {
                $meta['shipping']['id'] = (string) $meta['shipping']['id'];
            }
        }

        if ($includeBuyer && isset($payload['buyer']) && is_array($payload['buyer'])) {
            $buyerMeta = BuyerPresentation::metaFromProviderBuyer($payload['buyer']);
            if ($buyerMeta !== null) {
                $meta['buyer'] = $buyerMeta;
            }
        }

        $externalOrderId = (string) ($payload['id'] ?? $snapshot->external_id);
        $externalPackId = isset($payload['pack_id']) && $payload['pack_id'] !== null && $payload['pack_id'] !== ''
            ? (string) $payload['pack_id']
            : $externalOrderId;
        $meta['messaging_pack_id'] = $externalPackId;
        if (isset($payload['pack_id']) && $payload['pack_id'] !== null && $payload['pack_id'] !== '') {
            $meta['pack_id'] = (string) $payload['pack_id'];
        }

        $upsert = app(UpsertCanonicalOrder::class)->executeWithMeta($this->workspaceId, $this->connectionId, [
            'external_order_id' => $externalOrderId,
            'external_pack_id' => $externalPackId,
            'status' => $payload['status'] ?? 'pending',
            'buyer_external_id' => $includeBuyer && isset($payload['buyer']['id'])
                ? (string) $payload['buyer']['id']
                : null,
            'currency_code' => $payload['currency_id'] ?? 'MXN',
            'total_amount' => $payload['total_amount'] ?? '0',
            'ordered_at' => OrderProviderDates::orderedAt($payload) ?? now(),
            'paid_at' => OrderProviderDates::paidAt($payload),
            'raw_snapshot_id' => $snapshot->id,
            'meta' => $meta,
            'lines' => $lines,
        ]);
        $order = $upsert->order;
        $order->load(['lines.channelListingVariant.listing:id,logistic_type,external_item_id']);

        if ($this->applyInventoryEffects) {
            $status = strtolower((string) ($order->status ?? ''));
            if (in_array($status, ['cancelled', 'canceled'], true)) {
                app(ReleaseStock::class)->releaseActiveForOrder($this->workspaceId, (int) $order->id);
            } else {
                $hasFullSaleConfirmation = $this->orderHasFullSaleConfirmation($order);
                $reserve = app(ReserveStock::class);
                foreach ($order->lines as $line) {
                    if ($hasFullSaleConfirmation || $this->lineIsFullFulfillment($line)) {
                        continue;
                    }
                    $reserve->execute($line);
                }
            }
        }

        if ($resolve->isEnabled($connection, 'order_fees')) {
            app(RecordExpectedFinancialEvents::class)->execute($order);
        }

        if ($resolve->isEnabled($connection, 'ads') && $order->ordered_at !== null) {
            $order->loadMissing('lines');
            $orderedDate = $order->ordered_at->copy()
                ->timezone(BusinessDay::timezone())
                ->toDateString();
            $itemIds = $order->lines
                ->pluck('external_item_id')
                ->filter(fn ($id) => is_string($id) && trim($id) !== '')
                ->map(fn ($id) => trim((string) $id))
                ->unique()
                ->values();

            foreach ($itemIds as $itemId) {
                // Debounced grain reattr: full item+day GMV, never single-order inline.
                ReattributeAdsItemDayJob::dispatch(
                    $this->workspaceId,
                    $this->connectionId,
                    $itemId,
                    $orderedDate,
                )->delay(now()->addSeconds(20));
            }
        }

        $profit = app(CalculateExpectedProfit::class)->execute($order);

        app(SyncMarketplacePaymentForOrder::class)->execute($order, allowFetch: true);
        app(PromoteFinancialEventsToRealized::class)->execute($order->fresh());

        OutboxEvent::query()->create([
            'workspace_id' => $this->workspaceId,
            'event_type' => 'order.updated',
            'payload' => [
                'order_id' => $order->id,
                'external_order_id' => $order->external_order_id,
                'profit_snapshot_id' => $profit->id,
            ],
            'status' => 'pending',
            'available_at' => now(),
        ]);

        event(new OrderUpdated($order, isNew: $upsert->wasCreated));

        app(LinkOrphanClaimsToOrder::class)->execute($order);

        $shippingId = $meta['shipping']['id'] ?? null;
        if ($shippingId !== null && $shippingId !== '' && $resolve->isEnabled($connection, 'shipments')) {
            $shipmentFetch = new FetchExternalResourceJob(
                $this->workspaceId,
                $this->connectionId,
                'shipment',
                (string) $shippingId,
                projectSynchronously: $this->projectSynchronously,
            );

            if ($this->projectSynchronously) {
                Bus::dispatchSync($shipmentFetch);
            } else {
                Bus::dispatch($shipmentFetch);
            }
        }

        if ($resolve->isEnabled($connection, 'messages')) {
            $messagesJob = new SyncOrderMessagesJob(
                $this->workspaceId,
                $this->connectionId,
                (int) $order->id,
            );

            if ($this->projectSynchronously) {
                Bus::dispatchSync($messagesJob);
            } else {
                Bus::dispatch($messagesJob);
            }
        }
    }

    private function orderHasFullSaleConfirmation(Order $order): bool
    {
        $links = app(ResolveFullCommerceLinks::class);
        $operations = $links->findOperationsForCommerceIds(
            $this->workspaceId,
            $this->connectionId,
            $links->commerceIdsForOrder($order),
        );

        return $operations->contains(
            fn (FullStockOperation $op) => FullStockOperationType::isSaleConfirmation((string) $op->operation_type),
        );
    }

    private function lineIsFullFulfillment(OrderLine $line): bool
    {
        $logistic = $line->channelListingVariant?->listing?->logistic_type;
        if ($this->isFulfillmentLogistic($logistic)) {
            return true;
        }

        if (! is_string($line->external_item_id) || trim($line->external_item_id) === '') {
            return false;
        }

        $listingLogistic = ChannelListing::query()
            ->where('workspace_id', $line->workspace_id)
            ->where('connection_id', $line->connection_id)
            ->where('external_item_id', $line->external_item_id)
            ->value('logistic_type');

        return $this->isFulfillmentLogistic(is_string($listingLogistic) ? $listingLogistic : null);
    }

    private function isFulfillmentLogistic(?string $logisticType): bool
    {
        return is_string($logisticType) && strtolower(trim($logisticType)) === 'fulfillment';
    }
}
