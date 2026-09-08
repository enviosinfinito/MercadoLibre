<?php

namespace App\Domain\Inventory\Actions;

use App\Integrations\Support\LoggedHttpClient;
use App\Models\ChannelListing;
use App\Models\ChannelListingVariant;
use App\Models\ChannelStockLocation;
use App\Models\Connection;
use Illuminate\Support\Facades\Log;

final class SyncMercadoLibreListingStock
{
    public function __construct(
        private readonly LoggedHttpClient $http,
    ) {}

    /**
     * Pull distributed / fulfillment stock for a listing's variants and persist locations.
     *
     * @return int Number of location rows upserted
     */
    public function execute(ChannelListing $listing, bool $force = false): int
    {
        if ($listing->provider !== 'mercadolibre') {
            return 0;
        }

        $connection = Connection::query()->find($listing->connection_id);
        if ($connection === null) {
            return 0;
        }

        try {
            $token = app(\App\Domain\Integrations\Actions\EnsureFreshConnectionToken::class)
                ->execute($connection);
        } catch (\Throwable) {
            return 0;
        }

        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $upserted = 0;

        $variants = $listing->relationLoaded('variants')
            ? $listing->variants
            : $listing->variants()->get();

        foreach ($variants as $clv) {
            if (! $force && $clv->channel_stock_synced_at && $clv->channel_stock_synced_at->gt(now()->subHours(6))) {
                continue;
            }

            $userProductId = $clv->user_product_id;
            $inventoryId = $clv->inventory_id ?: $listing->inventory_id;

            if ($userProductId) {
                $upserted += $this->syncUserProductStock($clv, $base, $token, $userProductId);
            }

            if ($inventoryId) {
                $upserted += $this->syncFulfillmentStock($clv, $base, $token, $inventoryId);
            }

            $clv->channel_stock_synced_at = now();
            $clv->save();
        }

        return $upserted;
    }

    public function needsRefresh(ChannelListingVariant $clv): bool
    {
        if (! $clv->user_product_id && ! $clv->inventory_id && ! $clv->listing?->inventory_id) {
            return false;
        }

        return $clv->channel_stock_synced_at === null
            || $clv->channel_stock_synced_at->lt(now()->subHours(6));
    }

    private function syncUserProductStock(
        ChannelListingVariant $clv,
        string $base,
        string $token,
        string $userProductId,
    ): int {
        try {
            $response = $this->http->get($base.'/user-products/'.$userProductId.'/stock', [], $token);
            if ($response->failed()) {
                Log::info('ml.user_product_stock.failed', [
                    'user_product_id' => $userProductId,
                    'status' => $response->status(),
                ]);

                return 0;
            }

            $version = $response->header('x-version') ?? $response->header('X-Version');
            $body = $response->json() ?? [];
            $locations = $body['locations'] ?? $body['stock'] ?? $body;

            if (! is_array($locations)) {
                return 0;
            }

            // Normalize: sometimes response is { locations: [...] }, sometimes keyed by type
            if (isset($locations[0]) && is_array($locations[0])) {
                $rows = $locations;
            } else {
                $rows = [];
                foreach ($locations as $type => $payload) {
                    if (! is_array($payload)) {
                        continue;
                    }
                    if (isset($payload['quantity']) || isset($payload['available_quantity'])) {
                        $rows[] = array_merge($payload, ['type' => is_string($type) ? $type : ($payload['type'] ?? 'unknown')]);
                    } elseif (isset($payload[0])) {
                        foreach ($payload as $loc) {
                            if (is_array($loc)) {
                                $rows[] = array_merge($loc, ['type' => is_string($type) ? $type : ($loc['type'] ?? 'seller_warehouse')]);
                            }
                        }
                    }
                }
            }

            $count = 0;
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $type = (string) ($row['type'] ?? $row['location_type'] ?? 'selling_address');
                $qty = $row['quantity'] ?? $row['available_quantity'] ?? 0;
                $this->upsertLocation($clv, $type, $row, (string) $qty, is_string($version) ? $version : null);
                $count++;
            }

            return $count;
        } catch (\Throwable $e) {
            Log::warning('ml.user_product_stock.exception', [
                'user_product_id' => $userProductId,
                'message' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    private function syncFulfillmentStock(
        ChannelListingVariant $clv,
        string $base,
        string $token,
        string $inventoryId,
    ): int {
        try {
            $response = $this->http->get($base.'/inventories/'.$inventoryId.'/stock/fulfillment', [], $token);
            if ($response->failed()) {
                return 0;
            }

            $body = $response->json() ?? [];
            $qty = $body['available_quantity'] ?? $body['total'] ?? $body['quantity'] ?? 0;
            $notAvailable = $body['not_available_quantity'] ?? null;
            $notAvailableDetail = is_array($body['not_available_detail'] ?? null)
                ? $body['not_available_detail']
                : [];

            $this->upsertLocation(
                $clv,
                'meli_facility',
                [
                    'store_id' => null,
                    'network_node_id' => null,
                    'not_available_quantity' => $notAvailable,
                    'raw' => array_merge($body, [
                        'not_available_detail' => $notAvailableDetail,
                    ]),
                ],
                (string) $qty,
                null,
            );

            return 1;
        } catch (\Throwable $e) {
            Log::warning('ml.fulfillment_stock.exception', [
                'inventory_id' => $inventoryId,
                'message' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function upsertLocation(
        ChannelListingVariant $clv,
        string $type,
        array $row,
        string $qty,
        ?string $version,
    ): void {
        $storeId = isset($row['store_id']) ? (string) $row['store_id'] : null;
        $nodeId = isset($row['network_node_id']) ? (string) $row['network_node_id'] : null;

        ChannelStockLocation::query()->updateOrCreate(
            [
                'channel_listing_variant_id' => $clv->id,
                'location_type' => $type,
                'store_id' => $storeId ?? '',
                'network_node_id' => $nodeId ?? '',
            ],
            [
                'workspace_id' => $clv->workspace_id,
                'quantity' => $qty,
                'not_available_quantity' => isset($row['not_available_quantity'])
                    ? (string) $row['not_available_quantity']
                    : null,
                'stock_version' => $version,
                'meta' => $row['raw'] ?? $row,
                'synced_at' => now(),
            ],
        );
    }
}
