<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Actions\ResolveFullCommerceLinks;
use App\Domain\Inventory\Support\FullStockOperationType;
use App\Domain\Shared\Support\BusinessDay;
use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Concerns\RespondsWithFilteredSelection;
use App\Http\Controllers\Concerns\RespondsWithJsonPaginator;
use App\Http\Controllers\Controller;
use App\Http\Filters\Inventory\FullOperationsFilterRegistry;
use App\Http\Requests\Inventory\FullOperationsIndexFilterRequest;
use App\Models\Connection;
use App\Models\FullStockOperation;
use App\Models\Order;
use App\Models\ReturnCase;
use App\Models\Shipment;
use App\Models\Variant;
use App\Services\Listings\FilteredSelectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class FullOperationsController extends Controller
{
    use RespondsWithFilteredSelection;
    use RespondsWithJsonPaginator;

    public function __construct(
        private readonly ResolveFullCommerceLinks $resolveLinks,
    ) {}

    public function index(
        FullOperationsIndexFilterRequest $request,
        FullOperationsFilterRegistry $filterRegistry,
    ): Response|JsonResponse {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $filters = $request->filters();

        $query = FullStockOperation::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        $filterRegistry->apply($query, $filters, $workspaceId);

        $entries = $query->paginate(50)->withQueryString();
        $entries->setCollection($this->serializeRows($workspaceId, $entries->getCollection()));

        if ($this->wantsJsonWithoutInertia($request)) {
            return $this->jsonPaginator($entries);
        }

        $allConnections = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider', 'mercadolibre')
            ->orderBy('id')
            ->get(['id', 'display_name', 'external_user_id', 'provider', 'color', 'status']);

        $operationTypes = FullStockOperation::query()
            ->where('workspace_id', $workspaceId)
            ->distinct()
            ->orderBy('operation_type')
            ->pluck('operation_type')
            ->filter()
            ->values()
            ->all();

        if ($operationTypes === []) {
            $operationTypes = array_keys(FullStockOperationType::labels());
        }

        return Inertia::render('Inventory/FullOperations', [
            'entries' => $entries,
            'connections' => $allConnections->map(fn (Connection $c) => [
                'id' => $c->id,
                'display_name' => $c->display_name ?: $c->external_user_id,
                'provider' => $c->provider,
                'external_user_id' => $c->external_user_id,
                'color' => $c->color,
                'status' => $c->status,
            ])->values(),
            'filters' => $filters,
            'operation_types' => $operationTypes,
            'operation_type_labels' => FullStockOperationType::labels(),
            'family_labels' => FullStockOperationType::familyLabels(),
            'type_counts' => $this->typeCounts($workspaceId),
            'today_summary' => $this->todaySummary($workspaceId, $filters['connection_id']),
        ]);
    }

    public function allIds(
        FullOperationsIndexFilterRequest $request,
        FullOperationsFilterRegistry $filterRegistry,
    ): JsonResponse {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $query = FullStockOperation::query()->where('workspace_id', $workspaceId);
        $filterRegistry->apply($query, $request->filters(), $workspaceId);

        return $this->selectionAllIds($query);
    }

    public function filteredSums(
        FullOperationsIndexFilterRequest $request,
        FullOperationsFilterRegistry $filterRegistry,
    ): JsonResponse {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $query = FullStockOperation::query()->where('workspace_id', $workspaceId);
        $filterRegistry->apply($query, $request->filters(), $workspaceId);

        return $this->selectionFilteredSums(
            $query,
            FilteredSelectionService::coalesceSumExpressions([
                'available_quantity_delta',
                'not_available_quantity_delta',
            ], 'full_stock_operations'),
        );
    }

    public function show(FullStockOperation $fullStockOperation): JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);
        abort_unless((int) $fullStockOperation->workspace_id === (int) $workspaceId, 404);

        $fullStockOperation->load([
            'variant:id,sku,name,product_id',
            'connection:id,display_name,external_user_id,provider,color,site_id',
            'channelListingVariant:id,channel_listing_id,variant_id,inventory_id,sku_external,user_product_id',
            'channelListingVariant.listing:id,external_item_id,title,permalink,logistic_type,status',
            'marketplaceInbound:id,status,qty_sent,qty_confirmed,external_inbound_id,sent_at,matched_at,variant_id',
        ]);

        $refs = is_array($fullStockOperation->external_references)
            ? $fullStockOperation->external_references
            : [];

        $inboundId = $this->refValue($refs, 'inbound_id');
        $shipmentRef = $this->refValue($refs, 'shipment_id');

        $links = $this->resolveLinks->execute(
            (int) $workspaceId,
            (int) $fullStockOperation->connection_id,
            $refs,
        );

        /** @var Order|null $order */
        $order = $links['order'];
        /** @var Shipment|null $shipment */
        $shipment = $links['shipment'];
        /** @var ReturnCase|null $returnCase */
        $returnCase = $links['return_case'];

        $variant = $fullStockOperation->variant;
        $connection = $fullStockOperation->connection;
        $clv = $fullStockOperation->channelListingVariant;
        $type = (string) $fullStockOperation->operation_type;

        return response()->json([
            'operation' => [
                'id' => $fullStockOperation->id,
                'external_operation_id' => $fullStockOperation->external_operation_id,
                'operation_type' => $fullStockOperation->operation_type,
                'operation_type_label' => FullStockOperationType::label($type),
                'operation_type_family' => FullStockOperationType::familyForType($type),
                'pill_variant' => FullStockOperationType::pillVariant($type),
                'occurred_at' => optional($fullStockOperation->occurred_at)?->toIso8601String(),
                'seller_id' => $fullStockOperation->seller_id,
                'inventory_id' => $fullStockOperation->inventory_id,
                'seller_product_id' => $fullStockOperation->seller_product_id,
                'available_quantity_delta' => $fullStockOperation->available_quantity_delta !== null
                    ? (string) $fullStockOperation->available_quantity_delta
                    : null,
                'not_available_quantity_delta' => $fullStockOperation->not_available_quantity_delta !== null
                    ? (string) $fullStockOperation->not_available_quantity_delta
                    : null,
                'result_total' => $fullStockOperation->result_total !== null
                    ? (string) $fullStockOperation->result_total
                    : null,
                'result_available' => $fullStockOperation->result_available !== null
                    ? (string) $fullStockOperation->result_available
                    : null,
                'result_not_available' => $fullStockOperation->result_not_available !== null
                    ? (string) $fullStockOperation->result_not_available
                    : null,
                'not_available_detail' => $fullStockOperation->not_available_detail ?? [],
                'external_references' => $refs,
                'inbound_id' => $inboundId,
                'marketplace_inbound_id' => $fullStockOperation->marketplace_inbound_id,
                'raw' => $fullStockOperation->raw ?? [],
            ],
            'connection' => $connection ? [
                'id' => $connection->id,
                'display_name' => $connection->display_name ?: $connection->external_user_id,
                'provider' => $connection->provider,
                'external_user_id' => $connection->external_user_id,
                'color' => $connection->color,
            ] : null,
            'channel_listing' => $clv?->listing ? [
                'id' => $clv->listing->id,
                'external_item_id' => $clv->listing->external_item_id,
                'title' => $clv->listing->title,
                'permalink' => $clv->listing->permalink,
                'logistic_type' => $clv->listing->logistic_type,
                'status' => $clv->listing->status,
                'channel_listing_variant_id' => $clv->id,
                'user_product_id' => $clv->user_product_id,
                'sku_external' => $clv->sku_external,
            ] : null,
            'related' => [
                'variant' => $variant ? [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'name' => $variant->name,
                    'product_id' => $variant->product_id,
                ] : null,
                'shipment' => $shipment ? [
                    'id' => $shipment->id,
                    'external_shipment_id' => $shipment->external_shipment_id,
                    'status' => $shipment->status,
                    'tracking_number' => $shipment->tracking_number,
                    'order_id' => $shipment->order_id,
                ] : ($shipmentRef !== null && $order === null ? [
                    'id' => null,
                    'external_shipment_id' => $shipmentRef,
                    'status' => null,
                    'tracking_number' => null,
                    'order_id' => null,
                    'unmatched' => true,
                ] : null),
                'order' => $order ? [
                    'id' => $order->id,
                    'external_order_id' => $order->external_order_id,
                    'status' => $order->status,
                    'connection_id' => $order->connection_id,
                    'total_amount' => $order->total_amount !== null ? (string) $order->total_amount : null,
                    'currency_code' => $order->currency_code,
                    'ordered_at' => optional($order->ordered_at)?->toIso8601String(),
                ] : null,
                'return' => $returnCase ? [
                    'id' => $returnCase->id,
                    'external_return_id' => $returnCase->external_return_id,
                    'status' => $returnCase->status,
                    'outcome' => $returnCase->outcome,
                    'reason_label' => $returnCase->inferred_reason_label ?: $returnCase->reason_label,
                    'opened_at' => optional($returnCase->opened_at)?->toIso8601String(),
                    'closed_at' => optional($returnCase->closed_at)?->toIso8601String(),
                    'delivered_at' => optional($returnCase->delivered_at)?->toIso8601String(),
                    'days_to_return' => $returnCase->days_to_return,
                    'returned_amount' => $returnCase->returned_amount !== null
                        ? (string) $returnCase->returned_amount
                        : null,
                    'currency_code' => $returnCase->currency_code,
                ] : null,
                'match_via' => $links['match_via'],
                'marketplace_inbound' => $fullStockOperation->marketplaceInbound
                    ? [
                        'id' => $fullStockOperation->marketplaceInbound->id,
                        'status' => $fullStockOperation->marketplaceInbound->status,
                        'qty_sent' => (string) $fullStockOperation->marketplaceInbound->qty_sent,
                        'qty_confirmed' => $fullStockOperation->marketplaceInbound->qty_confirmed !== null
                            ? (string) $fullStockOperation->marketplaceInbound->qty_confirmed
                            : null,
                        'external_inbound_id' => $fullStockOperation->marketplaceInbound->external_inbound_id,
                        'sent_at' => optional($fullStockOperation->marketplaceInbound->sent_at)?->toIso8601String(),
                        'matched_at' => optional($fullStockOperation->marketplaceInbound->matched_at)?->toIso8601String(),
                    ]
                    : null,
            ],
        ]);
    }

    /**
     * @param  Collection<int, FullStockOperation>  $operations
     * @return Collection<int, array<string, mixed>>
     */
    private function serializeRows(int $workspaceId, Collection $operations): Collection
    {
        $variantIds = $operations->pluck('variant_id')->unique()->filter();
        $connectionIds = $operations->pluck('connection_id')->unique()->filter();

        $variants = Variant::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $variantIds)
            ->get(['id', 'sku', 'name'])
            ->keyBy('id');

        $connections = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $connectionIds)
            ->get(['id', 'display_name', 'external_user_id', 'provider', 'color', 'site_id'])
            ->keyBy('id');

        return $operations->map(function (FullStockOperation $op) use ($variants, $connections) {
            $variant = $op->variant_id !== null ? $variants->get($op->variant_id) : null;
            $connection = $connections->get($op->connection_id);
            $type = (string) $op->operation_type;

            return [
                'id' => $op->id,
                'external_operation_id' => $op->external_operation_id,
                'operation_type' => $op->operation_type,
                'operation_type_label' => FullStockOperationType::label($type),
                'operation_type_family' => FullStockOperationType::familyForType($type),
                'pill_variant' => FullStockOperationType::pillVariant($type),
                'occurred_at' => optional($op->occurred_at)?->toIso8601String(),
                'inventory_id' => $op->inventory_id,
                'seller_product_id' => $op->seller_product_id,
                'available_quantity_delta' => $op->available_quantity_delta !== null
                    ? (string) $op->available_quantity_delta
                    : null,
                'not_available_quantity_delta' => $op->not_available_quantity_delta !== null
                    ? (string) $op->not_available_quantity_delta
                    : null,
                'result_total' => $op->result_total !== null ? (string) $op->result_total : null,
                'result_available' => $op->result_available !== null ? (string) $op->result_available : null,
                'result_not_available' => $op->result_not_available !== null
                    ? (string) $op->result_not_available
                    : null,
                'external_references' => $op->external_references ?? [],
                'not_available_detail' => $op->not_available_detail ?? [],
                'variant' => $variant ? [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'name' => $variant->name,
                ] : null,
                'connection' => $connection ? [
                    'id' => $connection->id,
                    'display_name' => $connection->display_name ?: $connection->external_user_id,
                    'provider' => $connection->provider,
                    'external_user_id' => $connection->external_user_id,
                    'color' => $connection->color,
                ] : null,
            ];
        })->values();
    }

    /**
     * @return array<string, int>
     */
    private function typeCounts(int $workspaceId): array
    {
        $raw = FullStockOperation::query()
            ->where('workspace_id', $workspaceId)
            ->selectRaw('operation_type, count(*) as aggregate')
            ->groupBy('operation_type')
            ->pluck('aggregate', 'operation_type');

        $counts = [];
        foreach (array_keys(FullStockOperationType::familyLabels()) as $family) {
            $counts[$family] = 0;
        }

        $total = 0;
        foreach ($raw as $type => $count) {
            $n = (int) $count;
            $total += $n;
            $family = FullStockOperationType::familyForType((string) $type);
            $counts[$family] = ($counts[$family] ?? 0) + $n;
        }
        $counts[FullStockOperationType::FAMILY_ALL] = $total;

        return $counts;
    }

    /**
     * @return array{
     *     date: string,
     *     operations_today: int,
     *     operations_yesterday: int,
     *     net_available_today: float,
     *     net_available_yesterday: float,
     *     inbound_qty_today: float,
     *     inbound_qty_yesterday: float,
     *     sale_qty_today: float,
     *     sale_qty_yesterday: float
     * }
     */
    private function todaySummary(int $workspaceId, ?int $connectionId): array
    {
        $todayLocal = BusinessDay::today();
        [$todayStartUtc, $tomorrowStartUtc] = BusinessDay::utcRangeForDate($todayLocal);
        [$yesterdayStartUtc, $todayStartUtcAgain] = BusinessDay::utcRangeForDate($todayLocal->copy()->subDay());

        $inboundTypes = FullStockOperationType::typesForFamily(FullStockOperationType::FAMILY_INBOUND) ?? [];
        $saleTypes = FullStockOperationType::typesForFamily(FullStockOperationType::FAMILY_SALE) ?? [];

        $base = function () use ($workspaceId, $connectionId) {
            $query = FullStockOperation::query()->where('workspace_id', $workspaceId);
            if ($connectionId !== null) {
                $query->where('connection_id', $connectionId);
            }

            return $query;
        };

        $inRange = function ($start, $end) use ($base) {
            return $base()
                ->where('occurred_at', '>=', $start)
                ->where('occurred_at', '<', $end);
        };

        $opsToday = (clone $inRange($todayStartUtc, $tomorrowStartUtc))->count();
        $opsYesterday = (clone $inRange($yesterdayStartUtc, $todayStartUtcAgain))->count();

        $netAvailToday = (float) (clone $inRange($todayStartUtc, $tomorrowStartUtc))
            ->sum('available_quantity_delta');
        $netAvailYesterday = (float) (clone $inRange($yesterdayStartUtc, $todayStartUtcAgain))
            ->sum('available_quantity_delta');

        $inboundToday = $inboundTypes === []
            ? 0.0
            : (float) (clone $inRange($todayStartUtc, $tomorrowStartUtc))
                ->whereIn('operation_type', $inboundTypes)
                ->sum('available_quantity_delta');
        $inboundYesterday = $inboundTypes === []
            ? 0.0
            : (float) (clone $inRange($yesterdayStartUtc, $todayStartUtcAgain))
                ->whereIn('operation_type', $inboundTypes)
                ->sum('available_quantity_delta');

        $saleToday = $saleTypes === []
            ? 0.0
            : abs((float) (clone $inRange($todayStartUtc, $tomorrowStartUtc))
                ->whereIn('operation_type', $saleTypes)
                ->sum('available_quantity_delta'));
        $saleYesterday = $saleTypes === []
            ? 0.0
            : abs((float) (clone $inRange($yesterdayStartUtc, $todayStartUtcAgain))
                ->whereIn('operation_type', $saleTypes)
                ->sum('available_quantity_delta'));

        return [
            'date' => $todayLocal->toDateString(),
            'operations_today' => $opsToday,
            'operations_yesterday' => $opsYesterday,
            'net_available_today' => round($netAvailToday, 2),
            'net_available_yesterday' => round($netAvailYesterday, 2),
            'inbound_qty_today' => round($inboundToday, 2),
            'inbound_qty_yesterday' => round($inboundYesterday, 2),
            'sale_qty_today' => round($saleToday, 2),
            'sale_qty_yesterday' => round($saleYesterday, 2),
        ];
    }

    /**
     * @param  list<array<string, mixed>>|array<int, mixed>  $refs
     */
    private function refValue(array $refs, string $type): ?string
    {
        foreach ($refs as $ref) {
            if (! is_array($ref)) {
                continue;
            }
            if (($ref['type'] ?? null) !== $type) {
                continue;
            }
            $value = $ref['value'] ?? $ref['id'] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            return (string) $value;
        }

        return null;
    }
}
