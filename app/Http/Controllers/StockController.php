<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\Actions\BuildVariantInventoryTimeline;
use App\Domain\Inventory\Actions\SyncMercadoLibreListingStock;
use App\Domain\Inventory\Services\StockDepletionForecastService;
use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Concerns\RespondsWithFilteredSelection;
use App\Http\Filters\Stock\StockFilterRegistry;
use App\Http\Requests\Stock\IndexFilterRequest;
use App\Models\ChannelListingVariant;
use App\Models\ChannelStockLocation;
use App\Models\Connection;
use App\Models\CostLayer;
use App\Models\FullStockOperation;
use App\Models\InventoryBalance;
use App\Models\InventoryLedger;
use App\Models\MarketplaceInbound;
use App\Models\OutboundCommand;
use App\Models\Reservation;
use App\Models\Variant;
use App\Models\Warehouse;
use App\Models\Workspace;
use App\Services\Listings\FilteredSelectionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StockController extends Controller
{
    use RespondsWithFilteredSelection;

    public function index(IndexFilterRequest $request, StockFilterRegistry $filterRegistry): Response
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $workspace = Workspace::query()->findOrFail($workspaceId);

        $filters = $request->filters();

        $connections = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->orderBy('provider')
            ->get(['id', 'provider', 'external_user_id', 'status']);

        $warehouses = Warehouse::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'is_default', 'is_active']);

        $query = Variant::query()
            ->where('workspace_id', $workspaceId)
            ->with([
                'product:id,name',
                'channelListingVariants.listing:id,connection_id,provider,external_item_id,title,status',
            ])
            ->orderBy('sku');

        $filterRegistry->apply($query, $filters);

        // low_stock / channel_mismatch need computed totals — paginate then filter would skew pages;
        // when those flags are on, load a larger set and filter in memory (capped).
        $needsComputedFilter = ! empty($filters['low_stock']) || ! empty($filters['channel_mismatch']);

        if ($needsComputedFilter) {
            $all = $query->limit(500)->get();
            $variantIds = $all->pluck('id');
            $balances = $this->balancesGrouped($workspaceId, $variantIds);

            $rows = $all->map(fn (Variant $variant) => $this->mapStockRow(
                $variant,
                $balances->get($variant->id, collect()),
                $connections,
            ));
            $rows = $this->attachForecasts((int) $workspaceId, $rows);

            if (! empty($filters['low_stock'])) {
                $rows = $rows->filter(fn (array $row) => (bool) ($row['flags']['low_stock'] ?? false));
            }
            if (! empty($filters['channel_mismatch'])) {
                $rows = $rows->filter(fn (array $row) => $row['flags']['channel_mismatch']);
            }

            $rows = $rows->values();
            $page = max(1, (int) $request->input('page', 1));
            $perPage = 50;
            $slice = $rows->slice(($page - 1) * $perPage, $perPage)->values();
            $paginator = new LengthAwarePaginator(
                $slice,
                $rows->count(),
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()],
            );
        } else {
            $variants = $query->paginate(50)->withQueryString();
            $variantIds = $variants->getCollection()->pluck('id');
            $balances = $this->balancesGrouped($workspaceId, $variantIds);

            $rows = $variants->getCollection()->map(fn (Variant $variant) => $this->mapStockRow(
                $variant,
                $balances->get($variant->id, collect()),
                $connections,
            ));
            $rows = $this->attachForecasts((int) $workspaceId, $rows);
            $variants->setCollection($rows);
            $paginator = $variants;
        }

        $lastSync = OutboundCommand::query()
            ->where('workspace_id', $workspaceId)
            ->where('command_type', 'stock_update')
            ->orderByDesc('id')
            ->first(['id', 'status', 'dry_run', 'processed_at', 'created_at', 'last_error_redacted']);

        $marketplaceQ = $filters['q'] ?? null;

        return Inertia::render('Stock/Index', [
            'rows' => $paginator,
            'marketplace_rows' => $this->marketplaceRows((int) $workspaceId, $marketplaceQ),
            'marketplace_total' => (int) $this->marketplaceSelectionQuery((int) $workspaceId, $marketplaceQ)
                ->toBase()
                ->getCountForPagination(),
            'connections' => $connections,
            'warehouses' => $warehouses,
            'filters' => $filters,
            'outbound_dry_run' => (bool) $workspace->outbound_dry_run,
            'last_sync' => $lastSync,
        ]);
    }

    public function allIds(IndexFilterRequest $request, StockFilterRegistry $filterRegistry): JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $filters = $request->filters();

        if (($filters['tab'] ?? 'internal') === 'marketplace') {
            $query = $this->marketplaceSelectionQuery((int) $workspaceId, $filters['q'] ?? null);

            return $this->selectionAllIds($query);
        }

        return $this->internalSelectionAllIds((int) $workspaceId, $filters, $filterRegistry);
    }

    public function filteredSums(IndexFilterRequest $request, StockFilterRegistry $filterRegistry): JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $filters = $request->filters();

        if (($filters['tab'] ?? 'internal') === 'marketplace') {
            return $this->marketplaceSelectionSums((int) $workspaceId, $filters['q'] ?? null);
        }

        return $this->internalSelectionSums((int) $workspaceId, $filters, $filterRegistry);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function internalSelectionAllIds(int $workspaceId, array $filters, StockFilterRegistry $filterRegistry): JsonResponse
    {
        $query = Variant::query()->where('workspace_id', $workspaceId);
        $filterRegistry->apply($query, $filters);

        $needsComputedFilter = ! empty($filters['low_stock']) || ! empty($filters['channel_mismatch']);
        if (! $needsComputedFilter) {
            return $this->selectionAllIds($query);
        }

        $rows = $this->internalComputedRows($workspaceId, $query, $filters);
        if ($rows->count() > FilteredSelectionService::MAX_IDS) {
            return response()->json([
                'message' => 'Hay más de '.number_format(FilteredSelectionService::MAX_IDS).' registros. Aplica más filtros para seleccionar todos.',
                'total_count' => $rows->count(),
                'max' => FilteredSelectionService::MAX_IDS,
            ], 422);
        }

        return response()->json([
            'record_ids' => $rows->pluck('id')->values()->all(),
            'total_count' => $rows->count(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function internalSelectionSums(int $workspaceId, array $filters, StockFilterRegistry $filterRegistry): JsonResponse
    {
        $query = Variant::query()->where('workspace_id', $workspaceId);
        $filterRegistry->apply($query, $filters);

        $needsComputedFilter = ! empty($filters['low_stock']) || ! empty($filters['channel_mismatch']);
        if ($needsComputedFilter) {
            $rows = $this->internalComputedRows($workspaceId, $query, $filters);
            if ($rows->count() > FilteredSelectionService::MAX_IDS) {
                return response()->json([
                    'message' => 'Hay más de '.number_format(FilteredSelectionService::MAX_IDS).' registros. Aplica más filtros para calcular totales.',
                    'total_count' => $rows->count(),
                    'max' => FilteredSelectionService::MAX_IDS,
                ], 422);
            }

            return response()->json([
                'sums_by_key' => [
                    'quantity_on_hand' => (float) $rows->sum(fn (array $r) => (float) $r['quantity_on_hand']),
                    'quantity_reserved' => (float) $rows->sum(fn (array $r) => (float) $r['quantity_reserved']),
                    'quantity_available' => (float) $rows->sum(fn (array $r) => (float) $r['quantity_available']),
                ],
                'total_count' => $rows->count(),
            ]);
        }

        $count = (clone $query)->toBase()->getCountForPagination();
        if ($count > FilteredSelectionService::MAX_IDS) {
            return response()->json([
                'message' => 'Hay más de '.number_format(FilteredSelectionService::MAX_IDS).' registros. Aplica más filtros para calcular totales.',
                'total_count' => $count,
                'max' => FilteredSelectionService::MAX_IDS,
            ], 422);
        }

        $variantIds = (clone $query)->select('variants.id');
        $row = DB::table('inventory_balances as ib')
            ->join('inventory_items as ii', 'ii.id', '=', 'ib.inventory_item_id')
            ->whereIn('ii.variant_id', $variantIds)
            ->selectRaw('
                COALESCE(SUM(ib.quantity_on_hand), 0) as quantity_on_hand,
                COALESCE(SUM(ib.quantity_reserved), 0) as quantity_reserved,
                COALESCE(SUM(ib.quantity_available), 0) as quantity_available
            ')
            ->first();

        return response()->json([
            'sums_by_key' => [
                'quantity_on_hand' => (float) ($row->quantity_on_hand ?? 0),
                'quantity_reserved' => (float) ($row->quantity_reserved ?? 0),
                'quantity_available' => (float) ($row->quantity_available ?? 0),
            ],
            'total_count' => $count,
        ]);
    }

    /**
     * @param  Builder<Variant>  $query
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function internalComputedRows(int $workspaceId, $query, array $filters): Collection
    {
        $all = (clone $query)
            ->with([
                'product:id,name',
                'channelListingVariants.listing:id,connection_id,provider,external_item_id,title,status',
            ])
            ->limit(FilteredSelectionService::MAX_IDS + 1)
            ->get();

        $connections = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->get(['id', 'provider', 'external_user_id', 'status']);

        $balances = $this->balancesGrouped($workspaceId, $all->pluck('id'));

        $rows = $all->map(fn (Variant $variant) => $this->mapStockRow(
            $variant,
            $balances->get($variant->id, collect()),
            $connections,
        ));
        $rows = $this->attachForecasts($workspaceId, $rows);

        if (! empty($filters['low_stock'])) {
            $rows = $rows->filter(fn (array $row) => (bool) ($row['flags']['low_stock'] ?? false));
        }
        if (! empty($filters['channel_mismatch'])) {
            $rows = $rows->filter(fn (array $row) => $row['flags']['channel_mismatch']);
        }

        return $rows->values();
    }

    private function marketplaceSelectionQuery(int $workspaceId, ?string $q)
    {
        $query = ChannelListingVariant::query()
            ->where('workspace_id', $workspaceId)
            ->whereHas('listing', fn ($l) => $l->where('provider', 'mercadolibre'));

        if (filled($q)) {
            $query->where(function ($builder) use ($q) {
                $builder->where('sku_external', 'like', "%{$q}%")
                    ->orWhereHas('variant', fn ($v) => $v->where('sku', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%"))
                    ->orWhereHas('listing', fn ($l) => $l->where('title', 'like', "%{$q}%")->orWhere('external_item_id', 'like', "%{$q}%"));
            });
        }

        return $query;
    }

    private function marketplaceSelectionSums(int $workspaceId, ?string $q): JsonResponse
    {
        $query = $this->marketplaceSelectionQuery($workspaceId, $q);
        $count = (clone $query)->toBase()->getCountForPagination();

        if ($count > FilteredSelectionService::MAX_IDS) {
            return response()->json([
                'message' => 'Hay más de '.number_format(FilteredSelectionService::MAX_IDS).' registros. Aplica más filtros para calcular totales.',
                'total_count' => $count,
                'max' => FilteredSelectionService::MAX_IDS,
            ], 422);
        }

        $ids = (clone $query)->select('channel_listing_variants.id');

        $published = (float) (clone $query)->toBase()->sum('available_quantity');

        $locSums = DB::table('channel_stock_locations')
            ->whereIn('channel_listing_variant_id', $ids)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN location_type = 'seller_warehouse' THEN quantity ELSE 0 END), 0) as seller_warehouse_qty,
                COALESCE(SUM(CASE WHEN location_type = 'meli_facility' THEN quantity ELSE 0 END), 0) as meli_facility_qty,
                COALESCE(SUM(CASE WHEN location_type = 'selling_address' THEN quantity ELSE 0 END), 0) as selling_address_qty
            ")
            ->first();

        return response()->json([
            'sums_by_key' => [
                'published_quantity' => $published,
                'seller_warehouse_qty' => (float) ($locSums->seller_warehouse_qty ?? 0),
                'meli_facility_qty' => (float) ($locSums->meli_facility_qty ?? 0),
                'selling_address_qty' => (float) ($locSums->selling_address_qty ?? 0),
            ],
            'total_count' => $count,
        ]);
    }

    /**
     * Flat list of ML published stock + location breakdown (seller_warehouse / Full / etc).
     *
     * @return list<array<string, mixed>>
     */
    private function marketplaceRows(int $workspaceId, ?string $q): array
    {
        $query = $this->marketplaceSelectionQuery($workspaceId, $q)
            ->with([
                'listing:id,connection_id,provider,external_item_id,title,status,logistic_type,inventory_id',
                'variant:id,sku,name,product_id',
                'stockLocations',
            ])
            ->orderByDesc('id')
            ->limit(200);

        return $query->get()->map(function (ChannelListingVariant $clv) {
            $locations = $clv->stockLocations->map(fn (ChannelStockLocation $loc) => [
                'location_type' => $loc->location_type,
                'store_id' => $loc->store_id !== '' ? $loc->store_id : null,
                'network_node_id' => $loc->network_node_id !== '' ? $loc->network_node_id : null,
                'quantity' => (string) $loc->quantity,
                'not_available_quantity' => $loc->not_available_quantity !== null
                    ? (string) $loc->not_available_quantity
                    : null,
            ])->values()->all();

            $sellerWarehouseQty = $clv->stockLocations
                ->where('location_type', 'seller_warehouse')
                ->reduce(fn (string $c, ChannelStockLocation $l) => bcadd($c, (string) $l->quantity, 6), '0');
            $fullQty = $clv->stockLocations
                ->where('location_type', 'meli_facility')
                ->reduce(fn (string $c, ChannelStockLocation $l) => bcadd($c, (string) $l->quantity, 6), '0');
            $sellingAddressQty = $clv->stockLocations
                ->where('location_type', 'selling_address')
                ->reduce(fn (string $c, ChannelStockLocation $l) => bcadd($c, (string) $l->quantity, 6), '0');

            return [
                'id' => $clv->id,
                'channel_listing_variant_id' => $clv->id,
                'variant_id' => $clv->variant_id,
                'sku' => $clv->variant?->sku ?? $clv->sku_external,
                'title' => $clv->listing?->title,
                'external_item_id' => $clv->listing?->external_item_id,
                'listing_status' => $clv->listing?->status,
                'logistic_type' => $clv->listing?->logistic_type,
                'user_product_id' => $clv->user_product_id,
                'inventory_id' => $clv->inventory_id ?: $clv->listing?->inventory_id,
                'published_quantity' => $clv->available_quantity,
                'seller_warehouse_qty' => $sellerWarehouseQty,
                'meli_facility_qty' => $fullQty,
                'selling_address_qty' => $sellingAddressQty,
                'locations' => $locations,
                'channel_stock_synced_at' => optional($clv->channel_stock_synced_at)?->toIso8601String(),
            ];
        })->values()->all();
    }

    public function show(Request $request, Variant $variant): JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);
        abort_unless((int) $variant->workspace_id === (int) $workspaceId, 404);

        return response()->json($this->buildShowPayload($variant, (int) $workspaceId));
    }

    public function showMarketplace(ChannelListingVariant $channelListingVariant): JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);
        abort_unless((int) $channelListingVariant->workspace_id === (int) $workspaceId, 404);

        $channelListingVariant->load([
            'listing:id,connection_id,provider,external_item_id,title,status,logistic_type,inventory_id,permalink',
            'variant:id,sku,name,product_id',
            'stockLocations',
        ]);

        $syncer = app(SyncMercadoLibreListingStock::class);
        $listing = $channelListingVariant->listing;
        if ($listing !== null && $syncer->needsRefresh($channelListingVariant)) {
            $listing->loadMissing('variants');
            $syncer->execute($listing, force: true);
            $channelListingVariant->refresh()->load('stockLocations');
        }

        $locations = $channelListingVariant->stockLocations->map(fn (ChannelStockLocation $loc) => [
            'id' => $loc->id,
            'location_type' => $loc->location_type,
            'store_id' => $loc->store_id !== '' ? $loc->store_id : null,
            'network_node_id' => $loc->network_node_id !== '' ? $loc->network_node_id : null,
            'quantity' => (string) $loc->quantity,
            'not_available_quantity' => $loc->not_available_quantity !== null
                ? (string) $loc->not_available_quantity
                : null,
            'not_available_detail' => $this->normalizeNotAvailableDetail($loc->meta),
            'synced_at' => optional($loc->synced_at)?->toIso8601String(),
        ])->values();

        return response()->json([
            'channel_listing_variant' => [
                'id' => $channelListingVariant->id,
                'variant_id' => $channelListingVariant->variant_id,
                'sku' => $channelListingVariant->variant?->sku ?? $channelListingVariant->sku_external,
                'sku_external' => $channelListingVariant->sku_external,
                'title' => $channelListingVariant->listing?->title,
                'external_item_id' => $channelListingVariant->listing?->external_item_id,
                'permalink' => $channelListingVariant->listing?->permalink,
                'listing_status' => $channelListingVariant->listing?->status,
                'logistic_type' => $channelListingVariant->listing?->logistic_type,
                'user_product_id' => $channelListingVariant->user_product_id,
                'inventory_id' => $channelListingVariant->inventory_id
                    ?: $channelListingVariant->listing?->inventory_id,
                'published_quantity' => $channelListingVariant->available_quantity,
                'matched' => $channelListingVariant->variant_id !== null,
            ],
            'channel_stock_locations' => $locations,
        ]);
    }

    /**
     * @param  Collection<int, int|string>  $variantIds
     * @return Collection<int|string, Collection<int, InventoryBalance>>
     */
    private function balancesGrouped(int $workspaceId, Collection $variantIds): Collection
    {
        if ($variantIds->isEmpty()) {
            return collect();
        }

        return InventoryBalance::query()
            ->where('workspace_id', $workspaceId)
            ->whereHas('inventoryItem', fn ($q) => $q->whereIn('variant_id', $variantIds))
            ->with(['inventoryItem:id,variant_id,warehouse_id', 'inventoryItem.warehouse:id,code,name'])
            ->get()
            ->groupBy(fn (InventoryBalance $b) => $b->inventoryItem?->variant_id);
    }

    /**
     * @param  Collection<int, InventoryBalance>  $variantBalances
     * @param  Collection<int, Connection>  $connections
     * @return array<string, mixed>
     */
    private function mapStockRow(Variant $variant, Collection $variantBalances, Collection $connections): array
    {
        $onHand = '0';
        $reserved = '0';
        $available = '0';
        $warehouses = [];

        foreach ($variantBalances as $balance) {
            $onHand = bcadd($onHand, (string) $balance->quantity_on_hand, 6);
            $reserved = bcadd($reserved, (string) $balance->quantity_reserved, 6);
            $available = bcadd($available, (string) $balance->quantity_available, 6);
            $wh = $balance->inventoryItem?->warehouse;
            $warehouses[] = [
                'warehouse_id' => $balance->inventoryItem?->warehouse_id,
                'code' => $wh?->code,
                'name' => $wh?->name,
                'quantity_on_hand' => (string) $balance->quantity_on_hand,
                'quantity_reserved' => (string) $balance->quantity_reserved,
                'quantity_available' => (string) $balance->quantity_available,
            ];
        }

        $channels = $variant->channelListingVariants->map(function (ChannelListingVariant $clv) use ($connections) {
            $listing = $clv->listing;
            $connection = $connections->firstWhere('id', $listing?->connection_id);

            return [
                'channel_listing_variant_id' => $clv->id,
                'connection_id' => $listing?->connection_id,
                'provider' => $listing?->provider ?? $connection?->provider,
                'external_item_id' => $listing?->external_item_id,
                'external_variation_id' => $clv->external_variation_id,
                'listing_status' => $listing?->status,
                'available_quantity' => $clv->available_quantity,
                'stock_synced_at' => $clv->stock_synced_at,
            ];
        })->values()->all();

        $channelQty = null;
        foreach ($channels as $ch) {
            if ($ch['available_quantity'] !== null) {
                $channelQty = ($channelQty ?? 0) + (int) $ch['available_quantity'];
            }
        }

        $availableInt = (int) round((float) $available);
        $channelMismatch = $channelQty !== null && $channelQty !== $availableInt && count($channels) > 0;

        return [
            'id' => $variant->id,
            'sku' => $variant->sku,
            'name' => $variant->name ?? $variant->product?->name,
            'product_id' => $variant->product_id,
            'quantity_on_hand' => $onHand,
            'quantity_reserved' => $reserved,
            'quantity_available' => $available,
            'warehouses' => $warehouses,
            'channels' => $channels,
            'forecast' => null,
            'flags' => [
                'unmatched' => count($channels) === 0,
                'negative' => bccomp($available, '0', 6) < 0,
                'low_stock' => false,
                'channel_mismatch' => $channelMismatch,
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function attachForecasts(int $workspaceId, Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }

        $channelByVariant = [];
        $internalByVariant = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $internalByVariant[$id] = (float) $row['quantity_available'];

            $channels = is_array($row['channels'] ?? null) ? $row['channels'] : [];
            if ($channels === []) {
                // No channel match → fall back to internal.
                continue;
            }

            $channelQty = 0.0;
            foreach ($channels as $ch) {
                if (($ch['available_quantity'] ?? null) !== null) {
                    $channelQty += (float) $ch['available_quantity'];
                }
            }
            $channelByVariant[$id] = $channelQty;
        }

        $forecasts = app(StockDepletionForecastService::class)->forVariants(
            $workspaceId,
            array_keys($internalByVariant),
            $channelByVariant,
            $internalByVariant,
        );

        return $rows->map(function (array $row) use ($forecasts) {
            $forecast = $forecasts[(int) $row['id']] ?? null;
            $row['forecast'] = $forecast;
            $row['flags']['low_stock'] = (bool) ($forecast['low_stock'] ?? false);

            return $row;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function buildShowPayload(Variant $variant, int $workspaceId): array
    {
        $variant->load([
            'product:id,name',
            'channelListingVariants.listing:id,connection_id,provider,external_item_id,title,status,inventory_id,logistic_type',
        ]);

        $balances = InventoryBalance::query()
            ->where('workspace_id', $workspaceId)
            ->whereHas('inventoryItem', fn ($q) => $q->where('variant_id', $variant->id))
            ->with(['inventoryItem:id,variant_id,warehouse_id', 'inventoryItem.warehouse:id,code,name'])
            ->get();

        $connections = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->get(['id', 'provider', 'external_user_id', 'status']);

        $row = $this->mapStockRow($variant, $balances, $connections);
        $row = $this->attachForecasts($workspaceId, collect([$row]))->first();

        $reservations = Reservation::query()
            ->where('workspace_id', $workspaceId)
            ->where('variant_id', $variant->id)
            ->where('status', 'active')
            ->with('warehouse:id,code,name')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (Reservation $r) => [
                'id' => $r->id,
                'quantity' => (string) $r->quantity,
                'status' => $r->status,
                'order_id' => $r->order_id,
                'order_line_id' => $r->order_line_id,
                'warehouse' => $r->warehouse ? [
                    'id' => $r->warehouse->id,
                    'code' => $r->warehouse->code,
                    'name' => $r->warehouse->name,
                ] : null,
                'reserved_at' => optional($r->reserved_at)?->toIso8601String(),
            ]);

        $ledger = InventoryLedger::query()
            ->where('workspace_id', $workspaceId)
            ->where('variant_id', $variant->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->map(fn (InventoryLedger $entry) => [
                'id' => $entry->id,
                'movement_type' => $entry->movement_type,
                'quantity_delta' => (string) $entry->quantity_delta,
                'quantity_after' => $entry->quantity_after !== null ? (string) $entry->quantity_after : null,
                'warehouse_id' => $entry->warehouse_id,
                'occurred_at' => optional($entry->occurred_at)?->toIso8601String(),
                'meta' => $entry->meta,
            ]);

        $costLayers = CostLayer::query()
            ->where('workspace_id', $workspaceId)
            ->where('variant_id', $variant->id)
            ->where('qty_remaining', '>', 0)
            ->orderBy('received_at')
            ->limit(30)
            ->get()
            ->map(fn (CostLayer $layer) => [
                'id' => $layer->id,
                'warehouse_id' => $layer->warehouse_id,
                'qty_remaining' => (string) $layer->qty_remaining,
                'qty_original' => (string) $layer->qty_original,
                'unit_cost_amount' => (string) $layer->unit_cost_amount,
                'unit_cost_currency' => $layer->unit_cost_currency,
                'source_type' => $layer->source_type,
                'received_at' => optional($layer->received_at)?->toIso8601String(),
            ]);

        $warehouses = Warehouse::query()
            ->where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->get(['id', 'code', 'name', 'is_default']);

        $timeline = app(BuildVariantInventoryTimeline::class)->execute($workspaceId, (int) $variant->id);

        $connectionsForFull = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider', 'mercadolibre')
            ->orderBy('display_name')
            ->get(['id', 'display_name', 'external_user_id', 'provider', 'color', 'status']);

        $inbounds = MarketplaceInbound::query()
            ->where('workspace_id', $workspaceId)
            ->where('variant_id', $variant->id)
            ->orderByDesc('sent_at')
            ->limit(20)
            ->get()
            ->map(fn (MarketplaceInbound $inbound) => [
                'id' => $inbound->id,
                'connection_id' => $inbound->connection_id,
                'qty_sent' => (string) $inbound->qty_sent,
                'qty_confirmed' => $inbound->qty_confirmed !== null ? (string) $inbound->qty_confirmed : null,
                'status' => $inbound->status,
                'external_inbound_id' => $inbound->external_inbound_id,
                'full_stock_operation_id' => $inbound->full_stock_operation_id,
                'sent_at' => optional($inbound->sent_at)?->toIso8601String(),
            ]);

        $withdrawalOps = FullStockOperation::query()
            ->where('workspace_id', $workspaceId)
            ->where('variant_id', $variant->id)
            ->whereIn('operation_type', ['WITHDRAWAL_DELIVERY', 'WITHDRAWAL_DISCARDED'])
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get(['id', 'operation_type', 'occurred_at', 'available_quantity_delta']);

        $syncer = app(SyncMercadoLibreListingStock::class);
        foreach ($variant->channelListingVariants as $clv) {
            $listing = $clv->listing;
            if ($listing === null) {
                continue;
            }
            if ($syncer->needsRefresh($clv)) {
                $listing->loadMissing('variants');
                $syncer->execute($listing);
            }
        }

        $clvIds = $variant->channelListingVariants->pluck('id');
        $channelStockLocations = ChannelStockLocation::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('channel_listing_variant_id', $clvIds)
            ->orderBy('location_type')
            ->get()
            ->map(fn (ChannelStockLocation $loc) => [
                'id' => $loc->id,
                'channel_listing_variant_id' => $loc->channel_listing_variant_id,
                'location_type' => $loc->location_type,
                'store_id' => $loc->store_id !== '' ? $loc->store_id : null,
                'network_node_id' => $loc->network_node_id !== '' ? $loc->network_node_id : null,
                'quantity' => (string) $loc->quantity,
                'not_available_quantity' => $loc->not_available_quantity !== null
                    ? (string) $loc->not_available_quantity
                    : null,
                'not_available_detail' => $this->normalizeNotAvailableDetail(
                    is_array($loc->meta) ? $loc->meta : null
                ),
                'synced_at' => optional($loc->synced_at)?->toIso8601String(),
            ]);

        return [
            'variant' => $row,
            'reservations' => $reservations,
            'ledger' => $ledger,
            'cost_layers' => $costLayers,
            'warehouses' => $warehouses,
            'channel_stock_locations' => $channelStockLocations,
            'timeline' => $timeline,
            'connections' => $connectionsForFull,
            'marketplace_inbounds' => $inbounds,
            'full_withdrawals' => $withdrawalOps->map(fn (FullStockOperation $op) => [
                'id' => $op->id,
                'operation_type' => $op->operation_type,
                'occurred_at' => optional($op->occurred_at)?->toIso8601String(),
                'available_quantity_delta' => $op->available_quantity_delta !== null
                    ? (string) $op->available_quantity_delta
                    : null,
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @return list<array{status:string,quantity:string}>
     */
    private function normalizeNotAvailableDetail(?array $meta): array
    {
        if ($meta === null) {
            return [];
        }

        $detail = $meta['not_available_detail'] ?? null;
        if (! is_array($detail)) {
            return [];
        }

        $rows = [];
        foreach ($detail as $row) {
            if (! is_array($row)) {
                continue;
            }
            $status = trim((string) ($row['status'] ?? ''));
            if ($status === '') {
                continue;
            }
            $rows[] = [
                'status' => $status,
                'quantity' => (string) ($row['quantity'] ?? 0),
            ];
        }

        return $rows;
    }
}
