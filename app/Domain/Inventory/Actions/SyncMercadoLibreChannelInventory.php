<?php

namespace App\Domain\Inventory\Actions;

use App\Integrations\Support\LoggedHttpClient;
use App\Models\ChannelListing;
use App\Models\Connection;
use Illuminate\Support\Facades\Log;

/**
 * Enrich ML listings with user_product_id / inventory_id and pull distributed stock locations.
 */
final class SyncMercadoLibreChannelInventory
{
    public function __construct(
        private readonly LoggedHttpClient $http,
        private readonly SyncMercadoLibreListingStock $syncListingStock,
    ) {}

    /**
     * @return array{listings:int, enriched:int, locations_upserted:int, errors:int}
     */
    public function execute(int $workspaceId, ?int $connectionId = null, int $limit = 100): array
    {
        $stats = ['listings' => 0, 'enriched' => 0, 'locations_upserted' => 0, 'errors' => 0];

        $connectionQuery = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider', 'mercadolibre')
            ->where('status', 'active')
            ->with('credential');

        if ($connectionId !== null) {
            $connectionQuery->whereKey($connectionId);
        }

        $connections = $connectionQuery->get();
        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');

        foreach ($connections as $connection) {
            try {
                $token = app(\App\Domain\Integrations\Actions\EnsureFreshConnectionToken::class)
                    ->execute($connection);
            } catch (\Throwable) {
                continue;
            }

            $listings = ChannelListing::query()
                ->where('workspace_id', $workspaceId)
                ->where('connection_id', $connection->id)
                ->where('provider', 'mercadolibre')
                ->whereNotNull('external_item_id')
                ->with('variants')
                ->limit($limit)
                ->get();

            foreach ($listings as $listing) {
                $stats['listings']++;
                try {
                    $enriched = $this->enrichListingFromItem($listing, $base, $token);
                    if ($enriched) {
                        $stats['enriched']++;
                    }
                    $listing->load('variants');
                    $stats['locations_upserted'] += $this->syncListingStock->execute($listing, force: true);
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    Log::warning('ml.channel_inventory.sync_failed', [
                        'listing_id' => $listing->id,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $stats;
    }

    private function enrichListingFromItem(ChannelListing $listing, string $base, string $token): bool
    {
        $itemId = (string) $listing->external_item_id;
        $response = $this->http->get($base.'/items/'.$itemId, [
            'attributes' => 'id,user_product_id,inventory_id,shipping,available_quantity,variations,status,title',
        ], $token);

        if ($response->failed()) {
            return false;
        }

        $item = $response->json() ?? [];
        if (! is_array($item)) {
            return false;
        }

        $logisticType = null;
        $shipping = $item['shipping'] ?? null;
        if (is_array($shipping) && isset($shipping['logistic_type'])) {
            $logisticType = (string) $shipping['logistic_type'];
        }

        $listing->logistic_type = $logisticType ?? $listing->logistic_type;
        $listing->inventory_id = isset($item['inventory_id'])
            ? (string) $item['inventory_id']
            : $listing->inventory_id;
        if (is_array($shipping)) {
            $listing->shipping_meta = array_filter([
                'logistic_type' => $shipping['logistic_type'] ?? null,
                'mode' => $shipping['mode'] ?? null,
                'tags' => $shipping['tags'] ?? null,
            ], static fn ($v) => $v !== null && $v !== '');
        }
        $listing->save();

        $itemUserProductId = isset($item['user_product_id']) ? (string) $item['user_product_id'] : null;
        $itemInventoryId = isset($item['inventory_id']) ? (string) $item['inventory_id'] : null;
        $variations = is_array($item['variations'] ?? null) ? $item['variations'] : [];

        $changed = false;
        foreach ($listing->variants as $clv) {
            $match = null;
            foreach ($variations as $variation) {
                if (! is_array($variation)) {
                    continue;
                }
                if ((string) ($variation['id'] ?? '') === (string) $clv->external_variation_id) {
                    $match = $variation;
                    break;
                }
            }

            $up = $match && isset($match['user_product_id'])
                ? (string) $match['user_product_id']
                : $itemUserProductId;
            $inv = $match && isset($match['inventory_id'])
                ? (string) $match['inventory_id']
                : $itemInventoryId;

            if ($match && array_key_exists('available_quantity', $match)) {
                $clv->available_quantity = (int) $match['available_quantity'];
            } elseif (array_key_exists('available_quantity', $item) && (string) $clv->external_variation_id === '0') {
                $clv->available_quantity = (int) $item['available_quantity'];
            }

            if ($up !== null && $up !== '' && $clv->user_product_id !== $up) {
                $clv->user_product_id = $up;
                $changed = true;
            }
            if ($inv !== null && $inv !== '' && $clv->inventory_id !== $inv) {
                $clv->inventory_id = $inv;
                $changed = true;
            }

            $clv->channel_stock_synced_at = null;
            $clv->save();
        }

        return $changed || $itemUserProductId !== null || $itemInventoryId !== null;
    }
}
