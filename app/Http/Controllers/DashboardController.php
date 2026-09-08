<?php

namespace App\Http\Controllers;

use App\Domain\PostSale\Actions\ResolveOrderPostSaleOutcome;
use App\Domain\Sales\Support\BuyerPresentation;
use App\Domain\Sales\Support\OrderSalesClassification;
use App\Domain\Shared\Support\BusinessDay;
use App\Domain\Shared\Support\TenantContext;
use App\Models\Alert;
use App\Models\ChannelListing;
use App\Models\Claim;
use App\Models\Connection;
use App\Models\DeadLetter;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OutboundCommand;
use App\Models\ProfitSnapshot;
use App\Models\Question;
use App\Models\SyncRun;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $connections = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->orderBy('id')
            ->get([
                'id',
                'provider',
                'display_name',
                'external_user_id',
                'color',
                'status',
                'freshness_status',
                'last_synced_at',
                'needs_reauthorization',
            ]);

        $connectionIds = $this->resolvedConnectionIds($request, $connections);
        $scopedConnections = $connectionIds === null
            ? $connections
            : $connections->whereIn('id', $connectionIds)->values();

        $todayLocal = BusinessDay::today();
        [$todayStartUtc, $tomorrowStartUtc] = BusinessDay::utcRangeForDate($todayLocal);
        [$yesterdayStartUtc, $todayStartUtcAgain] = BusinessDay::utcRangeForDate($todayLocal->copy()->subDay());
        $rangeStartLocal = $todayLocal->copy()->subDays(29);
        $rangeStartUtc = $rangeStartLocal->copy()->utc();
        $prevRangeStartUtc = $todayLocal->copy()->subDays(59)->utc();
        $prevRangeEndExclusiveUtc = $todayLocal->copy()->subDays(29)->utc();

        $ordersToday = $this->successfulOrdersQuery($workspaceId, $connectionIds)
            ->where('ordered_at', '>=', $todayStartUtc)
            ->where('ordered_at', '<', $tomorrowStartUtc)
            ->count();

        $ordersYesterday = $this->successfulOrdersQuery($workspaceId, $connectionIds)
            ->where('ordered_at', '>=', $yesterdayStartUtc)
            ->where('ordered_at', '<', $todayStartUtcAgain)
            ->count();

        $revenueToday = (float) $this->successfulOrdersQuery($workspaceId, $connectionIds)
            ->where('ordered_at', '>=', $todayStartUtc)
            ->where('ordered_at', '<', $tomorrowStartUtc)
            ->sum('total_amount');

        $revenue30d = (float) $this->successfulOrdersQuery($workspaceId, $connectionIds)
            ->where('ordered_at', '>=', $rangeStartUtc)
            ->sum('total_amount');

        $revenuePrev30d = (float) $this->successfulOrdersQuery($workspaceId, $connectionIds)
            ->where('ordered_at', '>=', $prevRangeStartUtc)
            ->where('ordered_at', '<', $prevRangeEndExclusiveUtc)
            ->sum('total_amount');

        $orders30d = $this->successfulOrdersQuery($workspaceId, $connectionIds)
            ->where('ordered_at', '>=', $rangeStartUtc)
            ->count();

        $ordersPrev30d = $this->successfulOrdersQuery($workspaceId, $connectionIds)
            ->where('ordered_at', '>=', $prevRangeStartUtc)
            ->where('ordered_at', '<', $prevRangeEndExclusiveUtc)
            ->count();

        $openAlerts = Alert::query()
            ->where('workspace_id', $workspaceId)
            ->whereNull('acknowledged_at')
            ->where('status', '!=', 'resolved')
            ->count();

        $failedOutbound = OutboundCommand::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subDay())
            ->when($connectionIds !== null, fn (Builder $q) => $q->whereIn('connection_id', $connectionIds))
            ->count();

        $openDeadLetters = DeadLetter::query()
            ->where('workspace_id', $workspaceId)
            ->whereNull('resolved_at')
            ->when($connectionIds !== null, fn (Builder $q) => $q->whereIn('connection_id', $connectionIds))
            ->count();

        $healthyConnections = $scopedConnections->filter(function (Connection $c) {
            return $c->status === 'active'
                && ! $c->needs_reauthorization
                && ! in_array($c->freshness_status, ['stale', 'error'], true);
        })->count();

        $lastSync = SyncRun::query()
            ->where('workspace_id', $workspaceId)
            ->when($connectionIds !== null, fn (Builder $q) => $q->whereIn('connection_id', $connectionIds))
            ->orderByDesc('started_at')
            ->first(['id', 'status', 'resource_type', 'started_at', 'finished_at']);

        $profitSnapshotsQuery = ProfitSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->where('stage', 'expected');

        if ($connectionIds !== null) {
            $profitSnapshotsQuery->whereIn(
                'order_id',
                Order::query()
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('connection_id', $connectionIds)
                    ->select('id'),
            );
        }

        $profitSnapshots = $profitSnapshotsQuery->get([
            'revenue_amount',
            'fees_amount',
            'cogs_amount',
            'profit_amount',
            'currency_code',
            'is_incomplete',
        ]);

        $currencyOrderQuery = Order::query()->where('workspace_id', $workspaceId);
        $this->applyConnectionFilter($currencyOrderQuery, $connectionIds);

        $currency = $profitSnapshots->first()?->currency_code
            ?? $currencyOrderQuery->value('currency_code')
            ?? 'MXN';

        $finance = [
            'expected_profit' => round((float) $profitSnapshots->sum(fn ($s) => (float) $s->profit_amount), 2),
            'revenue' => round((float) $profitSnapshots->sum(fn ($s) => (float) $s->revenue_amount), 2),
            'fees' => round((float) $profitSnapshots->sum(fn ($s) => (float) $s->fees_amount), 2),
            'cogs' => round((float) $profitSnapshots->sum(fn ($s) => (float) $s->cogs_amount), 2),
            'incomplete_orders' => $profitSnapshots->where('is_incomplete', true)->count(),
            'currency' => $currency,
        ];

        $ordersByStatusQuery = Order::query()
            ->where('workspace_id', $workspaceId)
            ->where('ordered_at', '>=', $rangeStartUtc);
        $this->applyConnectionFilter($ordersByStatusQuery, $connectionIds);

        $ordersByStatus = $ordersByStatusQuery
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();

        $dayExpr = BusinessDay::dateSql('ordered_at');
        $dailyRaw = $this->successfulOrdersQuery($workspaceId, $connectionIds)
            ->where('ordered_at', '>=', $rangeStartUtc)
            ->selectRaw("{$dayExpr} as day")
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as revenue')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->day)->toDateString());

        $dailyUnsuccessfulQuery = Order::query()
            ->where('workspace_id', $workspaceId)
            ->where('ordered_at', '>=', $rangeStartUtc)
            ->where(function (Builder $q) {
                $q->whereIn('status', OrderSalesClassification::cancelledStatuses())
                    ->orWhereIn('post_sale_outcome', OrderSalesClassification::reversedOutcomes());
            });
        $this->applyConnectionFilter($dailyUnsuccessfulQuery, $connectionIds);

        $dailyUnsuccessfulRaw = $dailyUnsuccessfulQuery
            ->selectRaw("{$dayExpr} as day")
            ->selectRaw('COUNT(*) as orders_count')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->day)->toDateString());

        $ordersSeries = [];
        foreach (CarbonPeriod::create($rangeStartLocal, $todayLocal) as $day) {
            /** @var Carbon $day */
            $key = $day->toDateString();
            $row = $dailyRaw->get($key);
            $failRow = $dailyUnsuccessfulRaw->get($key);
            $ordersSeries[] = [
                'day' => $key,
                'label' => $day->format('d M'),
                'orders' => (int) ($row->orders_count ?? 0),
                'revenue' => round((float) ($row->revenue ?? 0), 2),
                'unsuccessful_orders' => (int) ($failRow->orders_count ?? 0),
            ];
        }

        $nameExpr = "COALESCE(NULLIF(products.name, ''), NULLIF(order_lines.title, ''), NULLIF(variants.name, ''), NULLIF(order_lines.sku, ''), 'Producto')";
        $connectionIdExpr = 'COALESCE(order_lines.connection_id, orders.connection_id)';

        $topProductRowsQuery = OrderLine::query()
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->leftJoin('variants', 'variants.id', '=', 'order_lines.variant_id')
            ->leftJoin('products', 'products.id', '=', 'variants.product_id')
            ->leftJoin('connections', 'connections.id', '=', DB::raw($connectionIdExpr))
            ->where('order_lines.workspace_id', $workspaceId)
            ->where('orders.workspace_id', $workspaceId)
            ->where('orders.ordered_at', '>=', $rangeStartUtc)
            ->whereNotIn('orders.status', ['cancelled', 'canceled'])
            ->where(function ($q) {
                $q->whereNull('orders.post_sale_outcome')
                    ->orWhereNotIn('orders.post_sale_outcome', [
                        ResolveOrderPostSaleOutcome::RETURNED,
                        ResolveOrderPostSaleOutcome::REFUNDED,
                        ResolveOrderPostSaleOutcome::PARTIAL_REFUNDED,
                    ]);
            });

        if ($connectionIds !== null) {
            $topProductRowsQuery->whereIn('orders.connection_id', $connectionIds);
        }

        $topProductRows = $topProductRowsQuery
            ->selectRaw('products.id as product_id')
            ->selectRaw("{$nameExpr} as name")
            ->selectRaw('MAX(order_lines.sku) as sku')
            ->selectRaw("{$connectionIdExpr} as connection_id")
            ->selectRaw("COALESCE(NULLIF(connections.display_name, ''), NULLIF(connections.external_user_id, ''), connections.provider, 'Sin conexión') as connection_account")
            ->selectRaw('connections.provider as connection_provider')
            ->selectRaw('connections.color as connection_color')
            ->selectRaw('SUM(order_lines.quantity) as units')
            ->selectRaw('COALESCE(SUM(order_lines.line_total_amount), 0) as revenue')
            ->groupByRaw("products.id, {$nameExpr}, {$connectionIdExpr}, connections.display_name, connections.provider, connections.external_user_id, connections.color")
            ->orderByDesc('revenue')
            ->get();

        $productTotals = [];
        foreach ($topProductRows as $row) {
            $key = $row->product_id !== null
                ? 'p:'.$row->product_id
                : 'n:'.mb_strtolower((string) $row->name);
            $productTotals[$key] = ($productTotals[$key] ?? 0) + (float) $row->revenue;
        }
        arsort($productTotals);
        $topProductKeys = array_slice(array_keys($productTotals), 0, 10);

        $topProducts = $topProductRows
            ->filter(function ($row) use ($topProductKeys) {
                $key = $row->product_id !== null
                    ? 'p:'.$row->product_id
                    : 'n:'.mb_strtolower((string) $row->name);

                return in_array($key, $topProductKeys, true);
            })
            ->map(function ($row) {
                $provider = (string) ($row->connection_provider ?? '');
                $providerNice = match ($provider) {
                    'mercadolibre' => 'Mercado Libre',
                    '' => 'Sin conexión',
                    default => $provider,
                };
                $account = trim((string) ($row->connection_account ?? ''));
                $connectionLabel = $account !== '' && $account !== $provider
                    ? $providerNice.' · '.$account
                    : $providerNice;

                return [
                    'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
                    'name' => (string) $row->name,
                    'sku' => $row->sku !== null && $row->sku !== '' ? mb_strtoupper((string) $row->sku) : null,
                    'connection_id' => $row->connection_id !== null ? (int) $row->connection_id : null,
                    'connection_label' => $connectionLabel,
                    'connection_color' => is_string($row->connection_color ?? null) ? $row->connection_color : null,
                    'units' => (float) $row->units,
                    'revenue' => round((float) $row->revenue, 2),
                ];
            })
            ->sortByDesc('revenue')
            ->values()
            ->take(16)
            ->values()
            ->all();

        $topReturnedProductRowsQuery = OrderLine::query()
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->leftJoin('variants', 'variants.id', '=', 'order_lines.variant_id')
            ->leftJoin('products', 'products.id', '=', 'variants.product_id')
            ->where('order_lines.workspace_id', $workspaceId)
            ->where('orders.workspace_id', $workspaceId)
            ->where('orders.ordered_at', '>=', $rangeStartUtc)
            ->whereIn('orders.post_sale_outcome', [
                ResolveOrderPostSaleOutcome::RETURNED,
                ResolveOrderPostSaleOutcome::REFUNDED,
                ResolveOrderPostSaleOutcome::PARTIAL_REFUNDED,
            ]);

        if ($connectionIds !== null) {
            $topReturnedProductRowsQuery->whereIn('orders.connection_id', $connectionIds);
        }

        $topReturnedProductRows = $topReturnedProductRowsQuery
            ->selectRaw('products.id as product_id')
            ->selectRaw("{$nameExpr} as name")
            ->selectRaw('MAX(order_lines.sku) as sku')
            ->selectRaw('COUNT(DISTINCT orders.id) as orders_count')
            ->selectRaw('SUM(order_lines.quantity) as units')
            ->selectRaw('COALESCE(SUM(order_lines.line_total_amount), 0) as revenue')
            ->groupByRaw("products.id, {$nameExpr}")
            ->orderByDesc('orders_count')
            ->limit(8)
            ->get();

        $topReturnedProducts = $topReturnedProductRows
            ->map(fn ($row) => [
                'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
                'name' => (string) $row->name,
                'sku' => $row->sku !== null && $row->sku !== '' ? mb_strtoupper((string) $row->sku) : null,
                'orders_count' => (int) $row->orders_count,
                'units' => (float) $row->units,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->values()
            ->all();

        $salesQuality = $this->buildSalesQuality($workspaceId, $rangeStartUtc, $connectionIds);

        $openClaims = Claim::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'opened')
            ->when($connectionIds !== null, fn (Builder $q) => $q->whereIn('connection_id', $connectionIds))
            ->count();

        $unansweredQuestions = Question::query()
            ->where('workspace_id', $workspaceId)
            ->whereNull('answered_at')
            ->where(function ($q) {
                $q->whereNull('status')
                    ->orWhereNotIn('status', ['CLOSED', 'ANSWERED', 'closed', 'answered']);
            })
            ->when($connectionIds !== null, fn (Builder $q) => $q->whereIn('connection_id', $connectionIds))
            ->count();

        $recentOrdersQuery = Order::query()
            ->where('workspace_id', $workspaceId)
            ->with(['connection:id,provider']);
        $this->applyConnectionFilter($recentOrdersQuery, $connectionIds);

        $recentOrders = $recentOrdersQuery
            ->orderByDesc('ordered_at')
            ->limit(8)
            ->get([
                'id',
                'connection_id',
                'external_order_id',
                'buyer_external_id',
                'status',
                'post_sale_outcome',
                'currency_code',
                'total_amount',
                'ordered_at',
                'meta',
            ])
            ->map(function (Order $order) {
                $metaBuyer = is_array($order->meta['buyer'] ?? null) ? $order->meta['buyer'] : null;

                return [
                    'id' => $order->id,
                    'external_order_id' => $order->external_order_id,
                    'status' => $order->status,
                    'post_sale_outcome' => $order->post_sale_outcome,
                    'currency_code' => $order->currency_code,
                    'total_amount' => $order->total_amount,
                    'ordered_at' => $order->ordered_at?->toIso8601String(),
                    'platform' => match ($order->connection?->provider) {
                        'mercadolibre' => 'Mercado Libre',
                        default => $order->connection?->provider,
                    },
                    'buyer_display' => BuyerPresentation::summaryParts(
                        $order->buyer_external_id,
                        $metaBuyer,
                    ),
                ];
            })
            ->values()
            ->all();

        $listingsWithoutCostQuery = ChannelListing::query()
            ->where('workspace_id', $workspaceId)
            ->withoutCost();
        if ($connectionIds !== null) {
            $listingsWithoutCostQuery->whereIn('connection_id', $connectionIds);
        }

        return Inertia::render('Dashboard', [
            'kpis' => [
                'orders_today' => $ordersToday,
                'orders_yesterday' => $ordersYesterday,
                'revenue_today' => round($revenueToday, 2),
                'revenue_30d' => round($revenue30d, 2),
                'revenue_prev_30d' => round($revenuePrev30d, 2),
                'orders_30d' => $orders30d,
                'orders_prev_30d' => $ordersPrev30d,
                'open_alerts' => $openAlerts,
                'failed_outbound_24h' => $failedOutbound,
                'open_dead_letters' => $openDeadLetters,
                'connections_healthy' => $healthyConnections,
                'connections_total' => $scopedConnections->count(),
                'listings_without_cost' => $listingsWithoutCostQuery->count(),
                'open_claims' => $openClaims,
                'unanswered_questions' => $unansweredQuestions,
                'currency' => $currency,
            ],
            'finance' => $finance,
            'orders_series' => $ordersSeries,
            'orders_by_status' => $ordersByStatus,
            'sales_quality' => $salesQuality,
            'top_products' => $topProducts,
            'top_returned_products' => $topReturnedProducts,
            'recent_orders' => $recentOrders,
            'connections' => $connections,
            'filters' => [
                'connection_ids' => $connectionIds ?? [],
            ],
            'last_sync' => $lastSync,
        ]);
    }

    /**
     * @param  Collection<int, Connection>  $connections
     * @return list<int>|null null = all connections (no filter)
     */
    private function resolvedConnectionIds(Request $request, Collection $connections): ?array
    {
        $allowed = $connections->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($allowed === []) {
            return null;
        }

        $raw = $request->input('connection_ids', []);
        if (! is_array($raw)) {
            $raw = $raw !== null && $raw !== '' ? [$raw] : [];
        }

        $requested = collect($raw)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($requested === []) {
            return null;
        }

        $valid = array_values(array_intersect($requested, $allowed));
        if ($valid === []) {
            return null;
        }

        sort($valid);
        $allowedSorted = $allowed;
        sort($allowedSorted);

        // Selecting every workspace connection is equivalent to no filter.
        if ($valid === $allowedSorted) {
            return null;
        }

        return $valid;
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  list<int>|null  $connectionIds
     */
    private function applyConnectionFilter(Builder $query, ?array $connectionIds, string $column = 'connection_id'): void
    {
        if ($connectionIds !== null) {
            $query->whereIn($column, $connectionIds);
        }
    }

    /**
     * @param  list<int>|null  $connectionIds
     * @return Builder<Order>
     */
    private function successfulOrdersQuery(int $workspaceId, ?array $connectionIds): Builder
    {
        $query = Order::query()
            ->where('workspace_id', $workspaceId)
            ->successful();
        $this->applyConnectionFilter($query, $connectionIds);

        return $query;
    }

    /**
     * @param  list<int>|null  $connectionIds
     * @return array<string, mixed>
     */
    private function buildSalesQuality(int $workspaceId, Carbon $rangeStartUtc, ?array $connectionIds): array
    {
        $base = Order::query()
            ->where('workspace_id', $workspaceId)
            ->where('ordered_at', '>=', $rangeStartUtc);
        $this->applyConnectionFilter($base, $connectionIds);

        $totalOrders = (clone $base)->count();

        $successfulOrders = (clone $base)->successful()->count();
        $successfulRevenue = (float) (clone $base)->successful()->sum('total_amount');

        $cancelledOrders = (clone $base)->cancelledStatus()->count();
        $cancelledRevenue = (float) (clone $base)->cancelledStatus()->sum('total_amount');

        $returnedOrders = (clone $base)
            ->where('post_sale_outcome', ResolveOrderPostSaleOutcome::RETURNED)
            ->count();
        $refundedOrders = (clone $base)
            ->where('post_sale_outcome', ResolveOrderPostSaleOutcome::REFUNDED)
            ->count();
        $partialRefundedOrders = (clone $base)
            ->where('post_sale_outcome', ResolveOrderPostSaleOutcome::PARTIAL_REFUNDED)
            ->count();
        $postSaleReversedOrders = $returnedOrders + $refundedOrders + $partialRefundedOrders;
        $postSaleReversedRevenue = (float) (clone $base)->postSaleReversed()->sum('total_amount');

        $claimOpenOrders = (clone $base)->claimOpen()->count();

        $cancellationRate = $totalOrders > 0
            ? round(($cancelledOrders / $totalOrders) * 100, 1)
            : 0.0;
        $returnRate = $totalOrders > 0
            ? round(($postSaleReversedOrders / $totalOrders) * 100, 1)
            : 0.0;

        return [
            'total_orders' => $totalOrders,
            'successful_orders' => $successfulOrders,
            'successful_revenue' => round($successfulRevenue, 2),
            'cancelled_orders' => $cancelledOrders,
            'cancelled_revenue' => round($cancelledRevenue, 2),
            'returned_orders' => $returnedOrders,
            'refunded_orders' => $refundedOrders,
            'partial_refunded_orders' => $partialRefundedOrders,
            'post_sale_reversed_orders' => $postSaleReversedOrders,
            'post_sale_reversed_revenue' => round($postSaleReversedRevenue, 2),
            'claim_open_orders' => $claimOpenOrders,
            'cancellation_rate' => $cancellationRate,
            'return_rate' => $returnRate,
        ];
    }
}
