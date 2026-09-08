<?php

namespace App\Domain\Outbound\Actions;

use App\Jobs\PushOutboundCommandJob;
use App\Models\ChannelListingVariant;
use App\Models\ChannelStockLocation;
use App\Models\InventoryBalance;
use App\Models\OutboundCommand;
use App\Models\Workspace;
use Illuminate\Support\Str;

final class EnqueueChannelStockSync
{
    /**
     * Enqueue stock_update outbound commands for all matched channel listings of a variant.
     *
     * @return int Number of commands enqueued
     */
    public function execute(int $workspaceId, int $variantId, ?bool $dryRun = null): int
    {
        $workspace = Workspace::query()->find($workspaceId);
        if ($workspace === null) {
            return 0;
        }

        $dryRun ??= (bool) $workspace->outbound_dry_run;

        $available = $this->availableQuantity($workspaceId, $variantId);

        $listingVariants = ChannelListingVariant::query()
            ->where('workspace_id', $workspaceId)
            ->where('variant_id', $variantId)
            ->with('listing')
            ->get();

        $enqueued = 0;

        foreach ($listingVariants as $listingVariant) {
            $listing = $listingVariant->listing;
            if ($listing === null || $listing->external_item_id === null || $listing->external_item_id === '') {
                continue;
            }

            $listingVariant->available_quantity = $available;
            $listingVariant->save();

            $payload = [
                'external_item_id' => $listing->external_item_id,
                'available_quantity' => $available,
                'channel_listing_id' => $listing->id,
                'channel_listing_variant_id' => $listingVariant->id,
                'external_variation_id' => $listingVariant->external_variation_id,
            ];

            $commandType = 'stock_update';

            if ($this->shouldPushSellerWarehouse($listing->connection_id, $listingVariant)) {
                $commandType = 'seller_warehouse_stock';
                $sellerLoc = ChannelStockLocation::query()
                    ->where('channel_listing_variant_id', $listingVariant->id)
                    ->where('location_type', 'seller_warehouse')
                    ->orderByDesc('synced_at')
                    ->first();

                $payload['stock_type'] = 'seller_warehouse';
                $payload['user_product_id'] = $listingVariant->user_product_id;
                $payload['stock_version'] = $sellerLoc?->stock_version;
                $payload['locations'] = [[
                    'store_id' => $sellerLoc && $sellerLoc->store_id !== '' ? $sellerLoc->store_id : null,
                    'network_node_id' => $sellerLoc && $sellerLoc->network_node_id !== ''
                        ? $sellerLoc->network_node_id
                        : null,
                    'quantity' => $available,
                ]];
            }

            $command = OutboundCommand::query()->create([
                'workspace_id' => $workspaceId,
                'connection_id' => $listing->connection_id,
                'command_type' => $commandType,
                'status' => 'pending',
                'dry_run' => $dryRun,
                'payload' => $payload,
                'idempotency_key' => 'stock:auto:'.$listingVariant->id.':'.Str::uuid()->toString(),
                'available_at' => now(),
            ]);

            PushOutboundCommandJob::dispatch(
                $workspaceId,
                (int) $listing->connection_id,
                (int) $command->id,
            );
            $enqueued++;
        }

        return $enqueued;
    }

    private function shouldPushSellerWarehouse(?int $connectionId, ChannelListingVariant $clv): bool
    {
        if (! filled($clv->user_product_id)) {
            return false;
        }

        return ChannelStockLocation::query()
            ->where('channel_listing_variant_id', $clv->id)
            ->where('location_type', 'seller_warehouse')
            ->exists();
    }

    private function availableQuantity(int $workspaceId, int $variantId): int
    {
        $balances = InventoryBalance::query()
            ->where('workspace_id', $workspaceId)
            ->whereHas('inventoryItem', fn ($q) => $q->where('variant_id', $variantId))
            ->get();

        if ($balances->isEmpty()) {
            return 0;
        }

        $total = '0';
        foreach ($balances as $balance) {
            $total = bcadd($total, (string) $balance->quantity_available, 6);
        }

        return (int) max(0, (float) $total);
    }
}
