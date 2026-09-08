<?php

namespace App\Domain\Automation\Actions;

use App\Models\Alert;
use App\Models\Connection;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\ProfitSnapshot;
use App\Models\Workspace;
use Illuminate\Support\Collection;

final class EvaluateRuleTemplates
{
    public const HIGH_RETURN_RATE_WINDOW_DAYS = 7;

    public const HIGH_RETURN_RATE_MIN_ORDERS = 10;

    public const HIGH_RETURN_RATE_THRESHOLD = 0.08;

    /**
     * Evaluate built-in alert templates for a workspace and create Alert rows.
     *
     * @return Collection<int, Alert>
     */
    public function execute(int $workspaceId): Collection
    {
        $workspace = Workspace::query()->findOrFail($workspaceId);
        $created = collect();

        $created = $created->merge($this->lowStock($workspace));
        $created = $created->merge($this->negativeMargin($workspace));
        $created = $created->merge($this->invalidToken($workspace));
        $created = $created->merge($this->syncStale($workspace));
        $created = $created->merge($this->highReturnRate($workspace));

        return $created;
    }

    /**
     * @return Collection<int, Alert>
     */
    private function lowStock(Workspace $workspace): Collection
    {
        $threshold = 5;
        $lows = InventoryBalance::query()
            ->where('workspace_id', $workspace->id)
            ->where('quantity_available', '<=', $threshold)
            ->limit(50)
            ->get();

        return $lows->map(function (InventoryBalance $balance) use ($workspace, $threshold) {
            return $this->upsertAlert(
                $workspace->id,
                'low_stock',
                'warning',
                'Low stock',
                "Inventory item #{$balance->inventory_item_id} available qty {$balance->quantity_available} <= {$threshold}.",
                [
                    'template' => 'low_stock',
                    'inventory_item_id' => $balance->inventory_item_id,
                    'quantity_available' => (string) $balance->quantity_available,
                    'threshold' => $threshold,
                ],
            );
        })->filter();
    }

    /**
     * @return Collection<int, Alert>
     */
    private function negativeMargin(Workspace $workspace): Collection
    {
        $negatives = ProfitSnapshot::query()
            ->where('workspace_id', $workspace->id)
            ->where('profit_amount', '<', 0)
            ->limit(50)
            ->get();

        return $negatives->map(function (ProfitSnapshot $snapshot) use ($workspace) {
            return $this->upsertAlert(
                $workspace->id,
                'negative_margin',
                'critical',
                'Negative margin',
                "Order #{$snapshot->order_id} expected profit {$snapshot->profit_amount} {$snapshot->currency_code}.",
                [
                    'template' => 'negative_margin',
                    'order_id' => $snapshot->order_id,
                    'profit_amount' => (string) $snapshot->profit_amount,
                ],
            );
        })->filter();
    }

    /**
     * @return Collection<int, Alert>
     */
    private function invalidToken(Workspace $workspace): Collection
    {
        $bad = Connection::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($q) {
                $q->where('needs_reauthorization', true)
                    ->orWhere('status', 'error')
                    ->orWhere('freshness_status', 'auth_error');
            })
            ->get();

        return $bad->map(function (Connection $connection) use ($workspace) {
            return $this->upsertAlert(
                $workspace->id,
                'invalid_token',
                'critical',
                'Invalid connection token',
                "Connection #{$connection->id} ({$connection->provider}) needs reauthorization.",
                [
                    'template' => 'invalid_token',
                    'connection_id' => $connection->id,
                    'provider' => $connection->provider,
                ],
            );
        })->filter();
    }

    /**
     * @return Collection<int, Alert>
     */
    private function syncStale(Workspace $workspace): Collection
    {
        $staleBefore = now()->subHours(24);

        $stale = Connection::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'active')
            ->where(function ($q) use ($staleBefore) {
                $q->whereNull('last_synced_at')
                    ->orWhere('last_synced_at', '<', $staleBefore);
            })
            ->get();

        return $stale->map(function (Connection $connection) use ($workspace) {
            return $this->upsertAlert(
                $workspace->id,
                'sync_stale',
                'warning',
                'Stale sync',
                "Connection #{$connection->id} has not synced in 24h.",
                [
                    'template' => 'sync_stale',
                    'connection_id' => $connection->id,
                    'last_synced_at' => optional($connection->last_synced_at)?->toIso8601String(),
                ],
            );
        })->filter();
    }

    /**
     * @return Collection<int, Alert>
     */
    private function highReturnRate(Workspace $workspace): Collection
    {
        $windowDays = self::HIGH_RETURN_RATE_WINDOW_DAYS;
        $minOrders = self::HIGH_RETURN_RATE_MIN_ORDERS;
        $threshold = self::HIGH_RETURN_RATE_THRESHOLD;
        $since = now()->subDays($windowDays);

        $total = Order::query()
            ->where('workspace_id', $workspace->id)
            ->where('ordered_at', '>=', $since)
            ->count();

        if ($total < $minOrders) {
            return collect();
        }

        $reversed = Order::query()
            ->where('workspace_id', $workspace->id)
            ->where('ordered_at', '>=', $since)
            ->postSaleReversed()
            ->count();

        $rate = $reversed / $total;
        if ($rate < $threshold) {
            return collect();
        }

        $ratePct = round($rate * 100, 1);
        $thresholdPct = round($threshold * 100, 1);
        $severity = $rate >= ($threshold * 1.5) ? 'critical' : 'warning';

        $alert = $this->upsertAlert(
            $workspace->id,
            'high_return_rate',
            $severity,
            'Tasa de devoluciones alta',
            "En los últimos {$windowDays} días, {$reversed} de {$total} órdenes ({$ratePct}%) terminaron en devolución o reembolso (umbral {$thresholdPct}%).",
            [
                // Stable keys only so re-runs the same day do not spam alerts.
                'template' => 'high_return_rate',
                'window_days' => $windowDays,
                'as_of' => now()->toDateString(),
                'threshold' => $thresholdPct,
            ],
        );

        return collect($alert ? [$alert] : []);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function upsertAlert(
        int $workspaceId,
        string $template,
        string $severity,
        string $title,
        string $body,
        array $context,
    ): ?Alert {
        $dedupeKey = $template.':'.md5(json_encode($context, JSON_THROW_ON_ERROR));

        $existing = Alert::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'open')
            ->where('title', $title)
            ->where('context->dedupe_key', $dedupeKey)
            ->first();

        if ($existing) {
            return null;
        }

        return Alert::query()->create([
            'workspace_id' => $workspaceId,
            'severity' => $severity,
            'title' => $title,
            'body' => $body,
            'status' => 'open',
            'context' => array_merge($context, ['dedupe_key' => $dedupeKey]),
            'triggered_at' => now(),
        ]);
    }
}
