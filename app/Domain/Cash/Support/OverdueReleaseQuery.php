<?php

namespace App\Domain\Cash\Support;

use App\Domain\PostSale\Actions\ResolveOrderPostSaleOutcome;
use App\Domain\Sales\Support\OrderSalesClassification;
use App\Models\MarketplacePayment;
use App\Models\Order;
use App\Models\ProfitSnapshot;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Delivered sales whose seller payout should already have been released.
 *
 * Kinds:
 * - orphan: no marketplace_payments
 * - unreleased: payment exists but not released (and no MP ledger release)
 * - held: in_mediation or claim_open (legitimate hold)
 * - overdue: orphan + unreleased (failures only, excludes held)
 */
final class OverdueReleaseQuery
{
    public const KIND_OVERDUE = 'overdue';

    public const KIND_ORPHAN = 'orphan';

    public const KIND_UNRELEASED = 'unreleased';

    public const KIND_HELD = 'held';

    /**
     * @return list<string>
     */
    public static function kinds(): array
    {
        return [
            self::KIND_OVERDUE,
            self::KIND_ORPHAN,
            self::KIND_UNRELEASED,
            self::KIND_HELD,
        ];
    }

    public function graceDays(): int
    {
        return max(0, (int) config('finance.cash.release_grace_days', 2));
    }

    public function cutoff(?Carbon $now = null): Carbon
    {
        return ($now ?? now())->copy()->subDays($this->graceDays());
    }

    /**
     * @param  Builder<Order>  $orders
     * @return Builder<Order>
     */
    public function constrain(Builder $orders, ?string $kind = null): Builder
    {
        $cutoff = $this->cutoff();
        $kind = is_string($kind) && $kind !== '' ? $kind : null;

        $orders
            ->tap(fn (Builder $q) => OrderSalesClassification::scopeSuccessful($q))
            ->whereHas('shipments', function (Builder $q) use ($cutoff): void {
                $q->where('status', 'delivered')
                    ->whereNotNull('delivered_at')
                    ->where('delivered_at', '<=', $cutoff);
            })
            ->whereDoesntHave('marketplacePayments', function (Builder $q): void {
                $q->where('is_released', true);
            })
            ->whereDoesntHave('marketplacePayments', function (Builder $q): void {
                $q->whereNotNull('money_release_at')
                    ->where('money_release_at', '>', now());
            })
            ->whereDoesntHave('cashReconciliationLinks', function (Builder $q): void {
                $q->whereHas('ledgerEntry', function (Builder $e): void {
                    $e->where(function (Builder $inner): void {
                        $inner->where('entry_type', 'release')
                            ->orWhere('is_released', true);
                    });
                });
            })
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('cash_ledger_entries')
                    ->whereColumn('cash_ledger_entries.workspace_id', 'orders.workspace_id')
                    ->whereColumn('cash_ledger_entries.external_order_id', 'orders.external_order_id')
                    ->whereNotNull('cash_ledger_entries.external_order_id')
                    ->where('cash_ledger_entries.external_order_id', '!=', '')
                    ->where(function ($e): void {
                        $e->where('entry_type', 'release')
                            ->orWhere('is_released', true);
                    });
            });

        return match ($kind) {
            self::KIND_ORPHAN => $this->constrainOrphan($orders),
            self::KIND_UNRELEASED => $this->constrainUnreleased($orders),
            self::KIND_HELD => $this->constrainHeld($orders),
            self::KIND_OVERDUE => $this->constrainNotHeld($orders),
            default => $orders,
        };
    }

    /**
     * @param  Builder<Order>  $orders
     * @return Builder<Order>
     */
    private function constrainHeld(Builder $orders): Builder
    {
        return $orders->where(function (Builder $q): void {
            $q->where('post_sale_outcome', ResolveOrderPostSaleOutcome::CLAIM_OPEN)
                ->orWhereHas('marketplacePayments', fn (Builder $p) => $p->where('status', 'in_mediation'));
        });
    }

    /**
     * @param  Builder<Order>  $orders
     * @return Builder<Order>
     */
    private function constrainNotHeld(Builder $orders): Builder
    {
        return $orders
            ->where(function (Builder $q): void {
                $q->whereNull('post_sale_outcome')
                    ->orWhere('post_sale_outcome', '!=', ResolveOrderPostSaleOutcome::CLAIM_OPEN);
            })
            ->whereDoesntHave('marketplacePayments', fn (Builder $p) => $p->where('status', 'in_mediation'));
    }

    /**
     * @param  Builder<Order>  $orders
     * @return Builder<Order>
     */
    private function constrainOrphan(Builder $orders): Builder
    {
        return $this->constrainNotHeld($orders)->whereDoesntHave('marketplacePayments');
    }

    /**
     * @param  Builder<Order>  $orders
     * @return Builder<Order>
     */
    private function constrainUnreleased(Builder $orders): Builder
    {
        return $this->constrainNotHeld($orders)->whereHas('marketplacePayments');
    }

    public function classify(Order $order): string
    {
        if ($order->post_sale_outcome === ResolveOrderPostSaleOutcome::CLAIM_OPEN) {
            return self::KIND_HELD;
        }

        $payments = $order->relationLoaded('marketplacePayments')
            ? $order->marketplacePayments
            : $order->marketplacePayments()->get(['id', 'order_id', 'status', 'is_released', 'net_received_amount', 'expected_net_amount', 'money_release_at']);

        if ($payments->contains(fn (MarketplacePayment $p) => $p->status === 'in_mediation')) {
            return self::KIND_HELD;
        }

        return $payments->isEmpty() ? self::KIND_ORPHAN : self::KIND_UNRELEASED;
    }

    public function deliveredAt(Order $order): ?Carbon
    {
        if ($order->relationLoaded('shipments')) {
            $shipment = $order->shipments
                ->filter(fn (Shipment $s) => $s->status === 'delivered' && $s->delivered_at !== null)
                ->sortByDesc(fn (Shipment $s) => $s->delivered_at?->timestamp ?? 0)
                ->first();

            return $shipment?->delivered_at;
        }

        $shipment = $order->shipments()
            ->where('status', 'delivered')
            ->whereNotNull('delivered_at')
            ->orderByDesc('delivered_at')
            ->first();

        return $shipment?->delivered_at;
    }

    public function expectedNet(Order $order): ?string
    {
        $payments = $order->relationLoaded('marketplacePayments')
            ? $order->marketplacePayments
            : $order->marketplacePayments()->get(['id', 'order_id', 'net_received_amount', 'expected_net_amount']);

        foreach ($payments as $payment) {
            $net = CashMoney::scale($payment->net_received_amount !== null ? (string) $payment->net_received_amount : null);
            if ($net !== null) {
                return $net;
            }
        }

        foreach ($payments as $payment) {
            $expected = CashMoney::scale($payment->expected_net_amount !== null ? (string) $payment->expected_net_amount : null);
            if ($expected !== null) {
                return $expected;
            }
        }

        $snapshot = $order->relationLoaded('profitSnapshots')
            ? $order->profitSnapshots->first()
            : ProfitSnapshot::query()
                ->where('order_id', $order->id)
                ->where('stage', 'expected')
                ->orderByDesc('id')
                ->first();

        $payload = is_array($snapshot?->payload) ? $snapshot->payload : [];
        $fromSnapshot = $payload['net_received_amount'] ?? $payload['marketplace_net_amount'] ?? null;

        return CashMoney::scale(is_numeric($fromSnapshot) ? (string) $fromSnapshot : null);
    }

    public function moneyReleaseAt(Order $order): ?Carbon
    {
        $payments = $order->relationLoaded('marketplacePayments')
            ? $order->marketplacePayments
            : $order->marketplacePayments()->get(['id', 'order_id', 'money_release_at']);

        $dated = $payments
            ->filter(fn (MarketplacePayment $p) => $p->money_release_at !== null)
            ->sortBy(fn (MarketplacePayment $p) => $p->money_release_at?->timestamp ?? 0)
            ->first();

        return $dated?->money_release_at;
    }

    /**
     * @return array{at: Carbon|null, source: 'ml'|'estimated'|null}
     */
    public function expectedReleaseAt(Order $order): array
    {
        $ml = $this->moneyReleaseAt($order);
        if ($ml !== null) {
            return ['at' => $ml, 'source' => 'ml'];
        }

        $delivered = $this->deliveredAt($order);
        if ($delivered === null) {
            return ['at' => null, 'source' => null];
        }

        return [
            'at' => $delivered->copy()->addDays($this->graceDays()),
            'source' => 'estimated',
        ];
    }

    /**
     * @return array{orphan: int, unreleased: int, held: int, overdue: int}
     */
    public function counts(int $workspaceId, ?int $connectionId = null): array
    {
        $base = Order::query()->where('workspace_id', $workspaceId);
        if ($connectionId !== null) {
            $base->where('connection_id', $connectionId);
        }

        $orphan = (clone $base);
        $this->constrain($orphan, self::KIND_ORPHAN);
        $unreleased = (clone $base);
        $this->constrain($unreleased, self::KIND_UNRELEASED);
        $held = (clone $base);
        $this->constrain($held, self::KIND_HELD);

        $orphanCount = $orphan->count();
        $unreleasedCount = $unreleased->count();

        return [
            'orphan' => $orphanCount,
            'unreleased' => $unreleasedCount,
            'held' => $held->count(),
            'overdue' => $orphanCount + $unreleasedCount,
        ];
    }
}
