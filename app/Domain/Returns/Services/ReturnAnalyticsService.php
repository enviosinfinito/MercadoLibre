<?php

namespace App\Domain\Returns\Services;

use App\Domain\Sales\Support\OrderSalesClassification;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ReturnAlert;
use App\Models\ReturnCase;
use App\Models\ReturnCaseItem;
use App\Models\ReturnProductDailyStat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ReturnAnalyticsService
{
    /**
     * @param  list<int>|null  $connectionIds
     * @return array<string, mixed>
     */
    public function kpis(
        int $workspaceId,
        Carbon $start,
        Carbon $end,
        Carbon $prevStart,
        Carbon $prevEnd,
        ?array $connectionIds = null,
    ): array {
        $current = $this->windowMetrics($workspaceId, $start, $end, $connectionIds);
        $previous = $this->windowMetrics($workspaceId, $prevStart, $prevEnd, $connectionIds);

        $topRate = $this->topProductByRate($workspaceId, $start, $end, $connectionIds);
        $topLoss = $this->topProductByLoss($workspaceId, $start, $end, $connectionIds);

        $alerts = ReturnAlert::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'open')
            ->whereIn('level', ['high', 'critical', 'attention'])
            ->count();

        return [
            'total_returns' => $current['return_count'],
            'total_returns_delta_pct' => $this->deltaPct($current['return_count'], $previous['return_count']),
            'returned_amount' => $current['returned_amount'],
            'returned_amount_delta_pct' => $this->deltaPct($current['returned_amount'], $previous['returned_amount']),
            'return_rate' => $current['return_rate'],
            'previous_return_rate' => $previous['return_rate'],
            'products_affected' => $current['products_affected'],
            'returned_units' => $current['returned_units'],
            'estimated_loss' => $current['estimated_loss'],
            'historical_avg_rate' => $this->historicalAverageRate($workspaceId, $end, $connectionIds),
            'top_rate_product' => $topRate,
            'top_loss_product' => $topLoss,
            'products_in_alert' => $alerts,
            'units_sold' => $current['units_sold'],
        ];
    }

    /**
     * @param  list<int>|null  $connectionIds
     * @return array{return_count: int, returned_units: int, returned_amount: float, estimated_loss: float, units_sold: int, return_rate: float, products_affected: int}
     */
    public function windowMetrics(int $workspaceId, Carbon $start, Carbon $end, ?array $connectionIds): array
    {
        $returns = ReturnCase::query()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('opened_at', [$start, $end]);
        $this->applyConnections($returns, $connectionIds, 'connection_id');

        $returnCount = (clone $returns)->count();
        $returnedAmount = (float) (clone $returns)->sum('returned_amount');
        $estimatedLoss = (float) (clone $returns)->sum(DB::raw('COALESCE(estimated_loss, returned_amount)'));

        $items = ReturnCaseItem::query()
            ->join('returns', 'returns.id', '=', 'return_case_items.return_id')
            ->where('return_case_items.workspace_id', $workspaceId)
            ->whereBetween('returns.opened_at', [$start, $end]);
        if ($connectionIds !== null && $connectionIds !== []) {
            $items->whereIn('returns.connection_id', $connectionIds);
        }
        $returnedUnits = (int) (clone $items)->sum('return_case_items.quantity');
        $productsAffected = (int) (clone $items)->distinct()->count(DB::raw('COALESCE(return_case_items.product_id, return_case_items.ml_item_id)'));

        $soldQuery = OrderLine::query()
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('order_lines.workspace_id', $workspaceId)
            ->whereBetween('orders.ordered_at', [$start, $end])
            ->whereNotIn('orders.status', OrderSalesClassification::cancelledStatuses());
        if ($connectionIds !== null && $connectionIds !== []) {
            $soldQuery->whereIn('order_lines.connection_id', $connectionIds);
        }
        $unitsSold = (int) $soldQuery->sum('order_lines.quantity');

        $ordersQuery = Order::query()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('ordered_at', [$start, $end]);
        $this->applyConnections($ordersQuery, $connectionIds, 'connection_id');
        $totalOrders = (clone $ordersQuery)->count();
        $reversedOrders = (clone $ordersQuery)
            ->whereIn('post_sale_outcome', config('returns.outcomes', OrderSalesClassification::reversedOutcomes()))
            ->count();

        $returnRate = $totalOrders > 0 ? round($reversedOrders / $totalOrders, 4) : 0.0;

        return [
            'return_count' => $returnCount,
            'returned_units' => $returnedUnits,
            'returned_amount' => round($returnedAmount, 2),
            'estimated_loss' => round($estimatedLoss, 2),
            'units_sold' => $unitsSold,
            'return_rate' => $returnRate,
            'products_affected' => $productsAffected,
        ];
    }

    /**
     * @param  list<int>|null  $connectionIds
     * @return array{name: string|null, rate: float, product_id: int|null, ml_item_id: string|null}|null
     */
    private function topProductByRate(int $workspaceId, Carbon $start, Carbon $end, ?array $connectionIds): ?array
    {
        $rows = app(ReturnProductAnalyticsService::class)
            ->productRows($workspaceId, $start, $end, $connectionIds, limit: 50);

        $best = null;
        foreach ($rows as $row) {
            if (($row['units_sold'] ?? 0) < (int) config('returns.sample.min_sales_for_patterns', 10)) {
                continue;
            }
            if ($best === null || $row['return_rate'] > $best['return_rate']) {
                $best = $row;
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'name' => $best['name'],
            'rate' => $best['return_rate'],
            'product_id' => $best['product_id'],
            'ml_item_id' => $best['ml_item_id'],
        ];
    }

    /**
     * @param  list<int>|null  $connectionIds
     * @return array{name: string|null, amount: float, product_id: int|null, ml_item_id: string|null}|null
     */
    private function topProductByLoss(int $workspaceId, Carbon $start, Carbon $end, ?array $connectionIds): ?array
    {
        $rows = app(ReturnProductAnalyticsService::class)
            ->productRows($workspaceId, $start, $end, $connectionIds, limit: 50, sort: 'returned_amount');

        $top = $rows[0] ?? null;
        if ($top === null) {
            return null;
        }

        return [
            'name' => $top['name'],
            'amount' => $top['returned_amount'],
            'product_id' => $top['product_id'],
            'ml_item_id' => $top['ml_item_id'],
        ];
    }

    /**
     * @param  list<int>|null  $connectionIds
     */
    private function historicalAverageRate(int $workspaceId, Carbon $end, ?array $connectionIds): float
    {
        $start = $end->copy()->subDays(90)->startOfDay();
        $metrics = $this->windowMetrics($workspaceId, $start, $end, $connectionIds);

        return $metrics['return_rate'];
    }

    private function deltaPct(float|int $current, float|int $previous): ?float
    {
        if ((float) $previous === 0.0) {
            return $current > 0 ? 100.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  list<int>|null  $connectionIds
     */
    private function applyConnections($query, ?array $connectionIds, string $column): void
    {
        if ($connectionIds !== null && $connectionIds !== []) {
            $query->whereIn($column, $connectionIds);
        }
    }
}
