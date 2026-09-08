<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Integrations\Actions\EnsureFreshConnectionToken;
use App\Domain\Inventory\Support\FullStockOperationType;
use App\Integrations\Support\LoggedHttpClient;
use App\Models\ChannelListing;
use App\Models\ChannelListingVariant;
use App\Models\Connection;
use App\Models\FullStockOperation;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

final class SyncMercadoLibreFullStockOperations
{
    public const MAX_WINDOW_DAYS = 60;

    public function __construct(
        private readonly LoggedHttpClient $http,
        private readonly MatchMarketplaceInbound $matchMarketplaceInbound,
        private readonly ReturnStockFromFull $returnStockFromFull,
    ) {}

    /**
     * Pull Full warehouse stock operations for a connection.
     *
     * @return array{
     *   fetched:int,
     *   upserted:int,
     *   windows:int,
     *   errors:int,
     *   by_type:array<string,int>,
     *   unmatched_inventory_ids:list<string>,
     *   date_from:string,
     *   date_to:string
     * }
     */
    public function execute(
        Connection $connection,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        int $lookbackDays = 15,
    ): array {
        $stats = [
            'fetched' => 0,
            'upserted' => 0,
            'windows' => 0,
            'errors' => 0,
            'by_type' => [],
            'unmatched_inventory_ids' => [],
            'date_from' => '',
            'date_to' => '',
        ];

        if ($connection->provider !== 'mercadolibre') {
            return $stats;
        }

        $sellerId = trim((string) $connection->external_user_id);
        if ($sellerId === '') {
            Log::warning('ml.full_operations.missing_seller_id', [
                'connection_id' => $connection->id,
            ]);

            return $stats;
        }

        try {
            $token = app(EnsureFreshConnectionToken::class)->execute($connection);
        } catch (\Throwable $e) {
            Log::warning('ml.full_operations.token_failed', [
                'connection_id' => $connection->id,
                'message' => $e->getMessage(),
            ]);
            $stats['errors']++;

            return $stats;
        }

        $end = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();
        $start = $from
            ? Carbon::parse($from)->startOfDay()
            : $end->copy()->subDays(max(1, $lookbackDays) - 1)->startOfDay();

        if ($start->gt($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        $stats['date_from'] = $start->toDateString();
        $stats['date_to'] = $end->toDateString();

        $inventoryIndex = $this->buildInventoryIndex((int) $connection->workspace_id, (int) $connection->id);
        $inventoryIds = array_keys($inventoryIndex);
        $unmatched = [];

        if ($inventoryIds === []) {
            Log::info('ml.full_operations.no_inventory_ids', [
                'connection_id' => $connection->id,
            ]);

            return $stats;
        }

        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $windows = $this->chunkDateWindows($start, $end);

        foreach ($windows as [$windowFrom, $windowTo]) {
            $stats['windows']++;
            foreach ($inventoryIds as $inventoryId) {
                try {
                    $pageStats = $this->syncWindow(
                        $connection,
                        $base,
                        $token,
                        $sellerId,
                        (string) $inventoryId,
                        $windowFrom,
                        $windowTo,
                        $inventoryIndex,
                        $unmatched,
                    );
                    $stats['fetched'] += $pageStats['fetched'];
                    $stats['upserted'] += $pageStats['upserted'];
                    foreach ($pageStats['by_type'] as $type => $count) {
                        $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + $count;
                    }
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    Log::warning('ml.full_operations.window_failed', [
                        'connection_id' => $connection->id,
                        'inventory_id' => $inventoryId,
                        'from' => $windowFrom->toDateString(),
                        'to' => $windowTo->toDateString(),
                        'message' => $e->getMessage(),
                    ]);
                }

                // Soft throttle to reduce ML 429s when scanning many inventory_ids.
                usleep(50_000);
            }
        }

        $stats['unmatched_inventory_ids'] = array_values(array_unique($unmatched));

        return $stats;
    }

    /**
     * @param  array<string, array{channel_listing_variant_id:int|null, variant_id:int|null}>  $inventoryIndex
     * @param  list<string>  $unmatched
     * @return array{fetched:int, upserted:int, by_type:array<string,int>}
     */
    private function syncWindow(
        Connection $connection,
        string $base,
        string $token,
        string $sellerId,
        string $inventoryId,
        CarbonInterface $from,
        CarbonInterface $to,
        array $inventoryIndex,
        array &$unmatched,
    ): array {
        $fetched = 0;
        $upserted = 0;
        $byType = [];
        $scroll = null;
        $guard = 0;

        // ML treats date_to as exclusive of that calendar day in some sites; bump by 1 day.
        $apiDateTo = Carbon::parse($to)->addDay()->toDateString();

        do {
            $guard++;
            if ($guard > 200) {
                throw new \RuntimeException('Full operations scroll exceeded safety limit.');
            }

            $query = [
                'seller_id' => $sellerId,
                'inventory_id' => $inventoryId,
                'date_from' => Carbon::parse($from)->toDateString(),
                'date_to' => $apiDateTo,
                'limit' => 1000,
            ];
            if (is_string($scroll) && $scroll !== '') {
                $query['scroll'] = $scroll;
            }

            $response = $this->http->get(
                $base.'/stock/fulfillment/operations/search',
                $query,
                $token,
            );

            if ($response->failed()) {
                if ($response->status() === 429) {
                    usleep(800_000);
                    $response = $this->http->get(
                        $base.'/stock/fulfillment/operations/search',
                        $query,
                        $token,
                    );
                }
            }

            if ($response->failed()) {
                throw new \RuntimeException(
                    'Full operations search failed: HTTP '.$response->status().' '.$response->body()
                );
            }

            $body = $response->json() ?? [];
            $results = is_array($body['results'] ?? null) ? $body['results'] : [];
            $paging = is_array($body['paging'] ?? null) ? $body['paging'] : [];
            $scroll = isset($paging['scroll']) && is_string($paging['scroll']) && $paging['scroll'] !== ''
                ? $paging['scroll']
                : null;

            foreach ($results as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $fetched++;
                $op = $this->upsertOperation($connection, $row, $inventoryIndex, $unmatched);
                if ($op !== null) {
                    $upserted++;
                    $byType[$op->operation_type] = ($byType[$op->operation_type] ?? 0) + 1;
                }
            }
        } while ($scroll !== null);

        return [
            'fetched' => $fetched,
            'upserted' => $upserted,
            'by_type' => $byType,
        ];
    }

    /**
     * @param  array<string, array{channel_listing_variant_id:int|null, variant_id:int|null}>  $inventoryIndex
     * @param  list<string>  $unmatched
     * @param  array<string, mixed>  $row
     */
    private function upsertOperation(
        Connection $connection,
        array $row,
        array $inventoryIndex,
        array &$unmatched,
    ): ?FullStockOperation {
        $externalId = $row['id'] ?? null;
        if ($externalId === null || $externalId === '') {
            return null;
        }

        $inventoryId = isset($row['inventory_id'])
            ? (string) $row['inventory_id']
            : (isset($row['seller_product_id']) ? (string) $row['seller_product_id'] : null);

        $type = FullStockOperationType::normalize((string) ($row['type'] ?? 'UNKNOWN'));
        $detail = is_array($row['detail'] ?? null) ? $row['detail'] : [];
        $result = is_array($row['result'] ?? null) ? $row['result'] : [];
        $refs = is_array($row['external_references'] ?? null) ? $row['external_references'] : [];

        $notAvailableDetail = $detail['not_available_detail']
            ?? $result['not_available_detail']
            ?? [];

        $links = $inventoryId !== null && $inventoryId !== ''
            ? ($inventoryIndex[$inventoryId] ?? null)
            : null;

        if ($inventoryId !== null && $inventoryId !== '' && $links === null) {
            $unmatched[] = $inventoryId;
        }

        $occurredAt = null;
        if (! empty($row['date_created'])) {
            try {
                $occurredAt = Carbon::parse((string) $row['date_created']);
            } catch (\Throwable) {
                $occurredAt = null;
            }
        }

        $operation = FullStockOperation::query()->updateOrCreate(
            [
                'connection_id' => $connection->id,
                'external_operation_id' => (string) $externalId,
            ],
            [
                'workspace_id' => $connection->workspace_id,
                'seller_id' => isset($row['seller_id']) ? (string) $row['seller_id'] : (string) $connection->external_user_id,
                'inventory_id' => $inventoryId,
                'seller_product_id' => isset($row['seller_product_id']) ? (string) $row['seller_product_id'] : null,
                'operation_type' => $type,
                'occurred_at' => $occurredAt,
                'available_quantity_delta' => $this->decimalOrNull($detail['available_quantity'] ?? null),
                'not_available_quantity_delta' => $this->decimalOrNull($detail['not_available_quantity'] ?? null),
                'result_total' => $this->decimalOrNull($result['total'] ?? null),
                'result_available' => $this->decimalOrNull($result['available_quantity'] ?? null),
                'result_not_available' => $this->decimalOrNull($result['not_available_quantity'] ?? null),
                'not_available_detail' => is_array($notAvailableDetail) ? $notAvailableDetail : [],
                'external_references' => $refs,
                'raw' => $row,
                'channel_listing_variant_id' => $links['channel_listing_variant_id'] ?? null,
                'variant_id' => $links['variant_id'] ?? null,
            ],
        );

        try {
            $this->matchMarketplaceInbound->execute($operation);
            $this->returnStockFromFull->maybeApplyFromFullOperation($operation->fresh() ?? $operation);
        } catch (\Throwable $e) {
            Log::warning('ml.full_operations.match_failed', [
                'connection_id' => $connection->id,
                'external_operation_id' => (string) $externalId,
                'message' => $e->getMessage(),
            ]);
        }

        return $operation;
    }

    /**
     * @return array<string, array{channel_listing_variant_id:int|null, variant_id:int|null}>
     */
    private function buildInventoryIndex(int $workspaceId, int $connectionId): array
    {
        $index = [];

        $variants = ChannelListingVariant::query()
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('inventory_id')
            ->where('inventory_id', '!=', '')
            ->whereHas('listing', fn ($q) => $q
                ->where('connection_id', $connectionId)
                ->where('provider', 'mercadolibre'))
            ->get(['id', 'inventory_id', 'variant_id']);

        foreach ($variants as $clv) {
            $key = (string) $clv->inventory_id;
            if ($key === '' || isset($index[$key])) {
                continue;
            }
            $index[$key] = [
                'channel_listing_variant_id' => (int) $clv->id,
                'variant_id' => $clv->variant_id !== null ? (int) $clv->variant_id : null,
            ];
        }

        $listings = ChannelListing::query()
            ->where('workspace_id', $workspaceId)
            ->where('connection_id', $connectionId)
            ->where('provider', 'mercadolibre')
            ->whereNotNull('inventory_id')
            ->where('inventory_id', '!=', '')
            ->with(['variants' => fn ($q) => $q->select('id', 'channel_listing_id', 'variant_id', 'inventory_id')])
            ->get(['id', 'inventory_id']);

        foreach ($listings as $listing) {
            $key = (string) $listing->inventory_id;
            if ($key === '' || isset($index[$key])) {
                continue;
            }
            $first = $listing->variants->first();
            $index[$key] = [
                'channel_listing_variant_id' => $first?->id !== null ? (int) $first->id : null,
                'variant_id' => $first?->variant_id !== null ? (int) $first->variant_id : null,
            ];
        }

        return $index;
    }

    /**
     * @return list<array{0:CarbonInterface,1:CarbonInterface}>
     */
    private function chunkDateWindows(CarbonInterface $from, CarbonInterface $to): array
    {
        $windows = [];
        $cursor = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();

        while ($cursor->lte($end)) {
            $windowEnd = $cursor->copy()->addDays(self::MAX_WINDOW_DAYS - 1)->endOfDay();
            if ($windowEnd->gt($end)) {
                $windowEnd = $end->copy();
            }
            $windows[] = [$cursor->copy(), $windowEnd];
            $cursor = $windowEnd->copy()->addDay()->startOfDay();
        }

        return $windows;
    }

    private function decimalOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (string) $value;
    }
}
