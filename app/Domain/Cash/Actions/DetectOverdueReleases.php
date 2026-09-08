<?php

namespace App\Domain\Cash\Actions;

use App\Domain\Cash\Support\CashMoney;
use App\Domain\Cash\Support\OverdueReleaseQuery;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Pagination\LengthAwarePaginator;

final class DetectOverdueReleases
{
    public function __construct(
        private readonly OverdueReleaseQuery $query,
    ) {}

    /**
     * @return array{
     *     paginator: LengthAwarePaginator<int, array<string, mixed>>,
     *     kpis: array<string, mixed>
     * }
     */
    public function execute(
        int $workspaceId,
        ?int $connectionId = null,
        ?string $kind = null,
        ?string $search = null,
        int $perPage = 40,
        int $page = 1,
        bool $withKpis = true,
    ): array {
        $kind = is_string($kind) && in_array($kind, OverdueReleaseQuery::kinds(), true) ? $kind : null;

        $query = Order::query()
            ->where('workspace_id', $workspaceId)
            ->with([
                'connection:id,display_name,color,provider,external_user_id',
                'marketplacePayments:id,order_id,status,status_detail,is_released,net_received_amount,expected_net_amount,money_release_at,currency_code',
                'profitSnapshots' => fn ($q) => $q->where('stage', 'expected')->orderByDesc('id'),
                'shipments' => fn ($q) => $q
                    ->where('status', 'delivered')
                    ->whereNotNull('delivered_at')
                    ->orderByDesc('delivered_at'),
            ]);

        if ($connectionId !== null) {
            $query->where('connection_id', $connectionId);
        }

        $this->query->constrain($query, $kind);

        $q = trim((string) $search);
        if ($q !== '') {
            $query->where(function ($builder) use ($q): void {
                $builder->where('external_order_id', 'like', '%'.$q.'%')
                    ->orWhere('id', $q);
            });
        }

        $query->orderByDesc(
            Shipment::query()
                ->select('delivered_at')
                ->whereColumn('shipments.order_id', 'orders.id')
                ->where('status', 'delivered')
                ->whereNotNull('delivered_at')
                ->orderByDesc('delivered_at')
                ->limit(1),
        )->orderByDesc('orders.id');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page)->withQueryString();

        $rows = $paginator->getCollection()->map(fn (Order $order) => $this->serialize($order))->values();
        $paginator->setCollection($rows);

        return [
            'paginator' => $paginator,
            'kpis' => $withKpis
                ? $this->kpis($workspaceId, $connectionId)
                : [
                    'orphan' => 0,
                    'unreleased' => 0,
                    'held' => 0,
                    'overdue' => 0,
                    'overdue_net_total' => CashMoney::zero(),
                    'currency_code' => 'MXN',
                ],
        ];
    }

    /**
     * @return array{orphan: int, unreleased: int, held: int, overdue: int, overdue_net_total: string, currency_code: string}
     */
    public function kpis(int $workspaceId, ?int $connectionId = null): array
    {
        $counts = $this->query->counts($workspaceId, $connectionId);
        $net = CashMoney::zero();
        $currency = 'MXN';

        $sumQuery = Order::query()->where('workspace_id', $workspaceId);
        if ($connectionId !== null) {
            $sumQuery->where('connection_id', $connectionId);
        }
        $this->query->constrain($sumQuery, OverdueReleaseQuery::KIND_OVERDUE);
        $sumQuery
            ->with([
                'marketplacePayments:id,order_id,net_received_amount,expected_net_amount,currency_code',
                'profitSnapshots' => fn ($q) => $q->where('stage', 'expected')->orderByDesc('id'),
            ])
            ->chunkById(100, function ($orders) use (&$net, &$currency): void {
                foreach ($orders as $order) {
                    if (is_string($order->currency_code) && $order->currency_code !== '') {
                        $currency = $order->currency_code;
                    }
                    $amount = $this->query->expectedNet($order);
                    if ($amount !== null) {
                        $net = CashMoney::add($net, $amount);
                    }
                }
            });

        return [
            ...$counts,
            'overdue_net_total' => $net,
            'currency_code' => $currency,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Order $order): array
    {
        $kind = $this->query->classify($order);
        $deliveredAt = $this->query->deliveredAt($order);
        $moneyReleaseAt = $this->query->moneyReleaseAt($order);
        $expectedRelease = $this->query->expectedReleaseAt($order);
        $payment = $order->marketplacePayments->sortByDesc('id')->first();
        $expectedNet = $this->query->expectedNet($order);

        return [
            'id' => $order->id,
            'order_id' => $order->id,
            'external_order_id' => $order->external_order_id,
            'connection_id' => $order->connection_id,
            'connection' => $order->connection,
            'status' => $order->status,
            'post_sale_outcome' => $order->post_sale_outcome,
            'kind' => $kind,
            'delivered_at' => $deliveredAt?->toIso8601String(),
            'money_release_at' => $moneyReleaseAt?->toIso8601String(),
            'expected_release_at' => $expectedRelease['at']?->toIso8601String(),
            'expected_release_source' => $expectedRelease['source'],
            'expected_net_amount' => $expectedNet,
            'net_received_amount' => $payment?->net_received_amount,
            'currency_code' => $payment?->currency_code ?: ($order->currency_code ?: 'MXN'),
            'payment_id' => $payment?->id,
            'payment_status' => $payment?->status,
            'is_released' => $payment?->is_released,
        ];
    }
}
