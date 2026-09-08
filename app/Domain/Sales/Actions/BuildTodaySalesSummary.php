<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Shared\Support\BusinessDay;
use App\Models\Order;
use App\Models\ProfitSnapshot;
use Illuminate\Database\Eloquent\Builder;

final class BuildTodaySalesSummary
{
    /**
     * @return array{
     *     orders_today: int,
     *     orders_yesterday: int,
     *     revenue_today: float,
     *     revenue_yesterday: float,
     *     expected_profit_today: float,
     *     incomplete_orders_today: int,
     *     avg_ticket_today: float,
     *     currency: string,
     *     date: string
     * }
     */
    public function execute(int $workspaceId, ?int $connectionId = null): array
    {
        $todayLocal = BusinessDay::today();
        [$todayStartUtc, $tomorrowStartUtc] = BusinessDay::utcRangeForDate($todayLocal);
        [$yesterdayStartUtc, $todayStartUtcAgain] = BusinessDay::utcRangeForDate($todayLocal->copy()->subDay());

        $ordersToday = $this->successfulOrdersQuery($workspaceId, $connectionId)
            ->where('ordered_at', '>=', $todayStartUtc)
            ->where('ordered_at', '<', $tomorrowStartUtc)
            ->count();

        $ordersYesterday = $this->successfulOrdersQuery($workspaceId, $connectionId)
            ->where('ordered_at', '>=', $yesterdayStartUtc)
            ->where('ordered_at', '<', $todayStartUtcAgain)
            ->count();

        $revenueToday = (float) $this->successfulOrdersQuery($workspaceId, $connectionId)
            ->where('ordered_at', '>=', $todayStartUtc)
            ->where('ordered_at', '<', $tomorrowStartUtc)
            ->sum('total_amount');

        $revenueYesterday = (float) $this->successfulOrdersQuery($workspaceId, $connectionId)
            ->where('ordered_at', '>=', $yesterdayStartUtc)
            ->where('ordered_at', '<', $todayStartUtcAgain)
            ->sum('total_amount');

        $todayOrderIds = $this->successfulOrdersQuery($workspaceId, $connectionId)
            ->where('ordered_at', '>=', $todayStartUtc)
            ->where('ordered_at', '<', $tomorrowStartUtc)
            ->pluck('id');

        $profitSnapshots = ProfitSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->where('stage', 'expected')
            ->whereIn('order_id', $todayOrderIds)
            ->get(['profit_amount', 'currency_code', 'is_incomplete']);

        $expectedProfitToday = round(
            (float) $profitSnapshots->sum(fn ($s) => (float) $s->profit_amount),
            2,
        );
        $incompleteOrdersToday = $profitSnapshots->where('is_incomplete', true)->count();

        $currencyQuery = Order::query()->where('workspace_id', $workspaceId);
        if ($connectionId !== null) {
            $currencyQuery->where('connection_id', $connectionId);
        }

        $currency = $profitSnapshots->first()?->currency_code
            ?? $currencyQuery->value('currency_code')
            ?? 'MXN';

        $avgTicketToday = $ordersToday > 0
            ? round($revenueToday / $ordersToday, 2)
            : 0.0;

        return [
            'orders_today' => $ordersToday,
            'orders_yesterday' => $ordersYesterday,
            'revenue_today' => round($revenueToday, 2),
            'revenue_yesterday' => round($revenueYesterday, 2),
            'expected_profit_today' => $expectedProfitToday,
            'incomplete_orders_today' => $incompleteOrdersToday,
            'avg_ticket_today' => $avgTicketToday,
            'currency' => (string) $currency,
            'date' => $todayLocal->toDateString(),
        ];
    }

    /**
     * @return Builder<Order>
     */
    private function successfulOrdersQuery(int $workspaceId, ?int $connectionId): Builder
    {
        $query = Order::query()
            ->where('workspace_id', $workspaceId)
            ->successful();

        if ($connectionId !== null) {
            $query->where('connection_id', $connectionId);
        }

        return $query;
    }
}
