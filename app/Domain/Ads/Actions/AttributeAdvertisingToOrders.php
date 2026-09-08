<?php

namespace App\Domain\Ads\Actions;

use App\Domain\Finance\Actions\CalculateExpectedProfit;
use App\Domain\Shared\Support\BusinessDay;
use App\Models\FinancialEvent;
use App\Models\Order;
use App\Models\OrderLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Allocate Product Ads spend to order lines without inventing spend or conversions.
 *
 * rate = cost ÷ max(ML attributed revenue, local GMV) for item+day.
 * - Requires ml_revenue (total_amount) > 0 — ML is the attribution source of truth.
 * - Local short vs ML → residual unallocated.
 * - Local heavy (orgánico) → diluye el rate; suma exacta = cost.
 * - ml_revenue = 0 → residual = cost (waste / no atribuido); no fee en órdenes.
 *
 * GMV and allocation always use the full item+day grain (never a single order alone).
 */
final class AttributeAdvertisingToOrders
{
    public const MODEL = 'blended_acos_ml_rate';

    public const MODEL_LEGACY = 'blended_acos';

    public const SOURCE = 'product_ads_item_daily';

    public const EVENT_ALLOCATED = 'expected_advertising';

    public const EVENT_UNALLOCATED = 'expected_advertising_unallocated';

    /** @var list<string> */
    private const CANCELLED_STATUSES = ['cancelled', 'canceled'];

    public function __construct(
        private readonly CalculateExpectedProfit $calculateExpectedProfit,
    ) {}

    /**
     * @param  array{
     *     date_from?: string,
     *     date_to?: string,
     *     order_id?: int,
     *     connection_id?: int,
     *     ml_item_id?: string,
     *     refresh_profit?: bool
     * }  $options
     * @return array{
     *     orders_touched: int,
     *     events_written: int,
     *     unallocated_events: int,
     *     spend_keys: int,
     *     allocated_amount: float,
     *     residual_amount: float
     * }
     */
    public function execute(int $workspaceId, array $options = []): array
    {
        $dateFromStr = Carbon::parse($options['date_from'] ?? now()->subDays(29)->toDateString())->toDateString();
        $dateToStr = Carbon::parse($options['date_to'] ?? now()->toDateString())->toDateString();
        $orderId = isset($options['order_id']) ? (int) $options['order_id'] : null;
        $connectionId = isset($options['connection_id']) ? (int) $options['connection_id'] : null;
        $mlItemId = isset($options['ml_item_id']) && is_string($options['ml_item_id']) && $options['ml_item_id'] !== ''
            ? (string) $options['ml_item_id']
            : null;
        $refreshProfit = (bool) ($options['refresh_profit'] ?? true);

        [$rangeStartUtc, $rangeEndUtc] = BusinessDay::utcRangeForDateStrings($dateFromStr, $dateToStr);

        $spendQuery = DB::table('ad_spend_daily')
            ->where('workspace_id', $workspaceId)
            ->whereDate('date', '>=', $dateFromStr)
            ->whereDate('date', '<=', $dateToStr);

        if ($connectionId) {
            $spendQuery->where('connection_id', $connectionId);
        }
        if ($mlItemId !== null) {
            $spendQuery->where('ml_item_id', $mlItemId);
        }

        /** @var Collection<string, object{ml_item_id: string, date: string, cost: string, ml_revenue: string, connection_id: int}> $spendByKey */
        $spendByKey = $spendQuery
            ->selectRaw('ml_item_id, date, connection_id, SUM(cost) as cost, SUM(COALESCE(total_amount, 0)) as ml_revenue')
            ->groupBy('ml_item_id', 'date', 'connection_id')
            ->get()
            ->keyBy(fn ($row) => $this->spendKey(
                (int) $row->connection_id,
                (string) $row->ml_item_id,
                Carbon::parse((string) $row->date)->toDateString(),
            ));

        // order_id: resolve keys from that order, then attribute the full grain (all siblings).
        if ($orderId !== null) {
            $seedKeys = $this->keysForOrder($workspaceId, $orderId);
            if ($seedKeys === []) {
                return $this->emptyResult(0);
            }
            $spendByKey = $spendByKey->filter(fn ($row, $key) => in_array($key, $seedKeys, true));
            // No spend for these keys → do not wipe existing allocated events.
            if ($spendByKey->isEmpty()) {
                return $this->emptyResult(0);
            }
        }

        if ($spendByKey->isEmpty()) {
            return $this->emptyResult(0);
        }

        $itemIds = $spendByKey->pluck('ml_item_id')->unique()->values()->all();
        $connectionIds = $spendByKey->pluck('connection_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        $linesQuery = OrderLine::query()
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('external_item_id')
            ->where('external_item_id', '!=', '')
            ->whereIn('external_item_id', $itemIds)
            ->whereHas('order', function ($q) use ($rangeStartUtc, $rangeEndUtc, $connectionIds, $connectionId) {
                $q->whereNotIn('status', self::CANCELLED_STATUSES);
                if ($connectionId) {
                    $q->where('connection_id', $connectionId);
                } else {
                    $q->whereIn('connection_id', $connectionIds);
                }
                if ($rangeStartUtc !== null) {
                    $q->where('ordered_at', '>=', $rangeStartUtc);
                }
                if ($rangeEndUtc !== null) {
                    $q->where('ordered_at', '<', $rangeEndUtc);
                }
            })
            ->with(['order:id,workspace_id,connection_id,currency_code,ordered_at,status']);

        $lines = $linesQuery->get()->filter(function (OrderLine $line) use ($spendByKey) {
            $order = $line->order;
            if ($order === null || $order->ordered_at === null) {
                return false;
            }
            $key = $this->spendKey(
                (int) $order->connection_id,
                (string) $line->external_item_id,
                $this->businessDate($order->ordered_at),
            );

            return $spendByKey->has($key);
        })->values();

        /** @var array<string, string> $gmvByKey */
        $gmvByKey = [];
        foreach ($lines as $line) {
            $order = $line->order;
            if ($order === null || $order->ordered_at === null) {
                continue;
            }
            $key = $this->spendKey(
                (int) $order->connection_id,
                (string) $line->external_item_id,
                $this->businessDate($order->ordered_at),
            );
            $gmvByKey[$key] = bcadd($gmvByKey[$key] ?? '0', (string) $line->line_total_amount, 6);
        }

        $eventsWritten = 0;
        $unallocatedEvents = 0;
        $allocatedAmount = '0';
        $residualAmount = '0';
        $touchedOrderIds = [];

        DB::transaction(function () use (
            $lines,
            $spendByKey,
            $gmvByKey,
            $workspaceId,
            $rangeStartUtc,
            $rangeEndUtc,
            $connectionId,
            &$eventsWritten,
            &$unallocatedEvents,
            &$allocatedAmount,
            &$residualAmount,
            &$touchedOrderIds,
        ) {
            $orderIds = $lines->pluck('order_id')->unique()->filter()->values()->all();

            if ($orderIds !== []) {
                FinancialEvent::query()
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('order_id', $orderIds)
                    ->where('stage', 'expected')
                    ->where('event_type', self::EVENT_ALLOCATED)
                    ->where(function ($q) {
                        $q->where('provenance->model', self::MODEL)
                            ->orWhere('provenance->model', self::MODEL_LEGACY);
                    })
                    ->delete();

                // Profit must refresh even when allocation is removed (e.g. ml_revenue=0 waste).
                foreach ($orderIds as $wipedOrderId) {
                    $touchedOrderIds[(int) $wipedOrderId] = true;
                }
            }

            // Wipe residuals only for spend keys being rewritten.
            $residualQuery = FinancialEvent::query()
                ->where('workspace_id', $workspaceId)
                ->where('stage', 'expected')
                ->where('event_type', self::EVENT_UNALLOCATED)
                ->whereNull('order_id');
            if ($connectionId) {
                $residualQuery->where('connection_id', $connectionId);
            }
            if ($rangeStartUtc !== null) {
                $residualQuery->where('occurred_at', '>=', $rangeStartUtc);
            }
            if ($rangeEndUtc !== null) {
                $residualQuery->where('occurred_at', '<', $rangeEndUtc);
            }
            $residualQuery->where(function ($q) use ($spendByKey) {
                foreach ($spendByKey as $spendKey => $_spendRow) {
                    [$cid, $itemId, $date] = explode('|', (string) $spendKey, 3);
                    $q->orWhere(function ($inner) use ($cid, $itemId, $date) {
                        $inner->where('connection_id', (int) $cid)
                            ->where('provenance->ml_item_id', $itemId)
                            ->where('provenance->date', $date);
                    });
                }
            })->delete();

            foreach ($lines as $line) {
                $order = $line->order;
                if ($order === null || $order->ordered_at === null) {
                    continue;
                }

                $date = $this->businessDate($order->ordered_at);
                $key = $this->spendKey((int) $order->connection_id, (string) $line->external_item_id, $date);
                $spendRow = $spendByKey->get($key);
                if ($spendRow === null) {
                    continue;
                }

                $adsCost = bcadd((string) $spendRow->cost, '0', 6);
                $mlRevenue = bcadd((string) ($spendRow->ml_revenue ?? '0'), '0', 6);
                if (bccomp($adsCost, '0', 6) !== 1) {
                    continue;
                }

                // ML attributed revenue is required — do not invent conversions on organic GMV.
                if (bccomp($mlRevenue, '0', 6) !== 1) {
                    continue;
                }

                $itemGmv = $gmvByKey[$key] ?? '0';
                if (bccomp($itemGmv, '0', 6) !== 1) {
                    continue;
                }

                // denom = max(mlRevenue, localGmv).
                $denom = bccomp($itemGmv, $mlRevenue, 6) === 1 ? $itemGmv : $mlRevenue;
                if (bccomp($denom, '0', 6) !== 1) {
                    continue;
                }

                $rate = bcdiv($adsCost, $denom, 8);
                $amount = bcmul((string) $line->line_total_amount, $rate, 6);
                if (bccomp($amount, '0', 6) !== 1) {
                    continue;
                }

                $coverage = bcdiv($itemGmv, $mlRevenue, 8);
                $residualForKey = '0';
                if (bccomp($itemGmv, $mlRevenue, 6) === -1) {
                    $allocatedAtKey = bcmul($itemGmv, $rate, 6);
                    $residualForKey = bcsub($adsCost, $allocatedAtKey, 6);
                    if (bccomp($residualForKey, '0', 6) === -1) {
                        $residualForKey = '0';
                    }
                }

                FinancialEvent::query()->create([
                    'workspace_id' => $workspaceId,
                    'connection_id' => $order->connection_id,
                    'order_id' => $order->id,
                    'order_line_id' => $line->id,
                    'calculation_version_id' => null,
                    'event_type' => self::EVENT_ALLOCATED,
                    'stage' => 'expected',
                    'amount' => bcmul($amount, '-1', 6),
                    'currency_code' => (string) ($line->currency_code ?: $order->currency_code ?: 'MXN'),
                    'reporting_amount' => bcmul($amount, '-1', 6),
                    'reporting_currency' => (string) ($line->currency_code ?: $order->currency_code ?: 'MXN'),
                    'occurred_at' => $order->ordered_at,
                    'provenance' => [
                        'source' => self::SOURCE,
                        'model' => self::MODEL,
                        'ads_cost' => $adsCost,
                        'ml_revenue' => $mlRevenue,
                        'item_gmv' => $itemGmv,
                        'denom' => $denom,
                        'coverage_ratio' => $coverage,
                        'residual' => $residualForKey,
                        'rate' => $rate,
                        'ml_item_id' => $line->external_item_id,
                        'date' => $date,
                        'note' => 'Publicidad a tasa cost÷max(revenue ML, GMV local). Solo ventas atribuidas por ML.',
                    ],
                ]);

                $eventsWritten++;
                $allocatedAmount = bcadd($allocatedAmount, $amount, 6);
                $touchedOrderIds[$order->id] = true;
            }

            foreach ($spendByKey as $key => $spendRow) {
                $adsCost = bcadd((string) $spendRow->cost, '0', 6);
                if (bccomp($adsCost, '0', 6) !== 1) {
                    continue;
                }

                $mlRevenue = bcadd((string) ($spendRow->ml_revenue ?? '0'), '0', 6);
                $itemGmv = $gmvByKey[$key] ?? '0';
                $date = Carbon::parse((string) $spendRow->date)->toDateString();

                if (bccomp($mlRevenue, '0', 6) !== 1) {
                    // No ML attribution → full residual (waste / organic). Do not charge orders.
                    $rate = null;
                    $allocated = '0';
                    $residual = $adsCost;
                    $coverage = null;
                    $note = 'Gasto sin ventas atribuidas por ML (waste / posible orgánico o búsqueda directa).';
                } elseif (bccomp($itemGmv, '0', 6) !== 1) {
                    $rate = null;
                    $allocated = '0';
                    $residual = $adsCost;
                    $coverage = '0';
                    $note = 'Gasto ads sin órdenes locales elegibles ese día.';
                } elseif (bccomp($itemGmv, $mlRevenue, 6) !== -1) {
                    // Local GMV covers attributed revenue: full cost allocated; no residual.
                    $denom = bccomp($itemGmv, $mlRevenue, 6) === 1 ? $itemGmv : $mlRevenue;
                    $rate = bcdiv($adsCost, $denom, 8);
                    $allocated = $adsCost;
                    $residual = '0';
                    $coverage = bcdiv($itemGmv, $mlRevenue, 8);
                    $note = 'Gasto ads sin órdenes locales suficientes para cubrir el revenue atribuido por ML.';
                } else {
                    $denom = $mlRevenue;
                    $rate = bcdiv($adsCost, $denom, 8);
                    $allocated = bcmul($itemGmv, $rate, 6);
                    if (bccomp($allocated, $adsCost, 6) === 1) {
                        $allocated = $adsCost;
                    }
                    $residual = bcsub($adsCost, $allocated, 6);
                    $coverage = bcdiv($itemGmv, $mlRevenue, 8);
                    $note = 'Gasto ads sin órdenes locales suficientes para cubrir el revenue atribuido por ML.';
                }

                if (bccomp($residual, '0', 6) !== 1) {
                    continue;
                }

                FinancialEvent::query()->create([
                    'workspace_id' => $workspaceId,
                    'connection_id' => (int) $spendRow->connection_id,
                    'order_id' => null,
                    'order_line_id' => null,
                    'calculation_version_id' => null,
                    'event_type' => self::EVENT_UNALLOCATED,
                    'stage' => 'expected',
                    'amount' => bcmul($residual, '-1', 6),
                    'currency_code' => 'MXN',
                    'reporting_amount' => bcmul($residual, '-1', 6),
                    'reporting_currency' => 'MXN',
                    'occurred_at' => Carbon::parse($date, BusinessDay::timezone())->startOfDay()->utc(),
                    'provenance' => [
                        'source' => self::SOURCE,
                        'model' => self::MODEL,
                        'ads_cost' => $adsCost,
                        'ml_revenue' => $mlRevenue,
                        'item_gmv' => $itemGmv,
                        'allocated' => $allocated,
                        'coverage_ratio' => $coverage,
                        'residual' => $residual,
                        'rate' => $rate,
                        'ml_item_id' => (string) $spendRow->ml_item_id,
                        'date' => $date,
                        'note' => $note,
                    ],
                ]);

                $unallocatedEvents++;
                $residualAmount = bcadd($residualAmount, $residual, 6);
            }
        });

        if ($refreshProfit) {
            foreach (array_keys($touchedOrderIds) as $id) {
                $order = Order::query()->find($id);
                if ($order !== null) {
                    $this->calculateExpectedProfit->execute($order);
                }
            }
        }

        return [
            'orders_touched' => count($touchedOrderIds),
            'events_written' => $eventsWritten,
            'unallocated_events' => $unallocatedEvents,
            'spend_keys' => $spendByKey->count(),
            'allocated_amount' => (float) $allocatedAmount,
            'residual_amount' => (float) $residualAmount,
        ];
    }

    /**
     * @return list<string>
     */
    private function keysForOrder(int $workspaceId, int $orderId): array
    {
        $order = Order::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey($orderId)
            ->with(['lines:id,order_id,external_item_id'])
            ->first();

        if ($order === null || $order->ordered_at === null) {
            return [];
        }

        $date = $this->businessDate($order->ordered_at);
        $keys = [];
        foreach ($order->lines as $line) {
            $itemId = trim((string) ($line->external_item_id ?? ''));
            if ($itemId === '') {
                continue;
            }
            $keys[] = $this->spendKey((int) $order->connection_id, $itemId, $date);
        }

        return array_values(array_unique($keys));
    }

    private function businessDate(Carbon $orderedAt): string
    {
        return $orderedAt->copy()->timezone(BusinessDay::timezone())->toDateString();
    }

    private function spendKey(int $connectionId, string $mlItemId, string $date): string
    {
        return $connectionId.'|'.$mlItemId.'|'.$date;
    }

    /**
     * @return array{
     *     orders_touched: int,
     *     events_written: int,
     *     unallocated_events: int,
     *     spend_keys: int,
     *     allocated_amount: float,
     *     residual_amount: float
     * }
     */
    private function emptyResult(int $spendKeys): array
    {
        return [
            'orders_touched' => 0,
            'events_written' => 0,
            'unallocated_events' => 0,
            'spend_keys' => $spendKeys,
            'allocated_amount' => 0.0,
            'residual_amount' => 0.0,
        ];
    }
}
