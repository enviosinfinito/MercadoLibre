<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Support\BuyerPresentation;
use App\Models\Order;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class BuildBuyerInsights
{
    private const EXCLUDED_STATUSES = ['cancelled', 'canceled', 'refunded'];

    private const SPENT_STATUSES = ['paid', 'shipped', 'delivered'];

    /**
     * @return array{
     *   buyer: array<string, mixed>,
     *   insights: array{
     *     orders_count: int,
     *     lifetime_spent: string,
     *     currency_code: string,
     *     average_order_value: string,
     *     first_ordered_at: mixed,
     *     last_ordered_at: mixed,
     *     repeat_customer: bool
     *   },
     *   orders: list<array<string, mixed>>
     * }
     */
    public function execute(Order $order, int $recentLimit = 15): array
    {
        $buyerId = $order->buyer_external_id;
        if ($buyerId === null || $buyerId === '') {
            throw new InvalidArgumentException('La orden no tiene comprador asociado.');
        }

        /** @var Collection<int, Order> $allForBuyer */
        $allForBuyer = Order::query()
            ->where('workspace_id', $order->workspace_id)
            ->where('buyer_external_id', $buyerId)
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'external_order_id',
                'status',
                'total_amount',
                'currency_code',
                'ordered_at',
                'meta',
            ]);

        $counted = $allForBuyer->filter(
            fn (Order $row) => ! in_array(strtolower((string) $row->status), self::EXCLUDED_STATUSES, true),
        );

        $spentOrders = $counted->filter(
            fn (Order $row) => in_array(strtolower((string) $row->status), self::SPENT_STATUSES, true),
        );

        $ordersCount = $counted->count();
        $lifetimeSpent = '0.000000';
        foreach ($spentOrders as $row) {
            $lifetimeSpent = bcadd($lifetimeSpent, (string) $row->total_amount, 6);
        }

        $average = $ordersCount > 0
            ? bcdiv($lifetimeSpent, (string) $ordersCount, 6)
            : '0.000000';

        // Prefer the majority / current order currency for display.
        $currency = (string) ($order->currency_code ?: 'MXN');
        $currencyCounts = $spentOrders
            ->groupBy(fn (Order $row) => (string) ($row->currency_code ?: 'MXN'))
            ->map->count()
            ->sortDesc();
        if ($currencyCounts->isNotEmpty()) {
            $currency = (string) $currencyCounts->keys()->first();
        }

        $firstOrderedAt = $counted->min('ordered_at');
        $lastOrderedAt = $counted->max('ordered_at');

        $metaBuyer = is_array($order->meta['buyer'] ?? null) ? $order->meta['buyer'] : [];
        $buyer = array_merge(
            ['id' => (string) $buyerId],
            $metaBuyer,
        );
        if (! isset($buyer['id']) || $buyer['id'] === '') {
            $buyer['id'] = (string) $buyerId;
        }

        $recent = $allForBuyer
            ->take(max(1, $recentLimit))
            ->map(fn (Order $row) => [
                'id' => $row->id,
                'external_order_id' => $row->external_order_id,
                'status' => $row->status,
                'total_amount' => (string) $row->total_amount,
                'currency_code' => $row->currency_code,
                'ordered_at' => $row->ordered_at,
                'is_current' => (int) $row->id === (int) $order->id,
            ])
            ->values()
            ->all();

        return [
            'buyer' => $buyer,
            'insights' => [
                'orders_count' => $ordersCount,
                'lifetime_spent' => $lifetimeSpent,
                'currency_code' => $currency,
                'average_order_value' => $average,
                'first_ordered_at' => $firstOrderedAt,
                'last_ordered_at' => $lastOrderedAt,
                'repeat_customer' => $ordersCount > 1,
                'summary_label' => BuyerPresentation::summary($buyerId, $metaBuyer),
            ],
            'orders' => $recent,
        ];
    }
}
