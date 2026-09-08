<?php

namespace App\Domain\Billing\Actions;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stripe-ready usage metering stub: increments usage_counters and checks plan limits.
 */
final class RecordUsage
{
    /**
     * @param  array{metric_key:string, quantity?:int, period_key?:string}  $input
     * @return array{counter: UsageCounter, plan: ?Plan, within_limits: bool, limit: ?int}
     */
    public function execute(int $workspaceId, array $input): array
    {
        $metricKey = (string) ($input['metric_key'] ?? '');
        if ($metricKey === '') {
            throw new RuntimeException('metric_key is required');
        }

        $quantity = max(1, (int) ($input['quantity'] ?? 1));
        $periodKey = (string) ($input['period_key'] ?? now()->format('Y-m'));

        return DB::transaction(function () use ($workspaceId, $metricKey, $quantity, $periodKey) {
            $workspace = Workspace::query()->findOrFail($workspaceId);

            $subscription = Subscription::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', 'active')
                ->latest('id')
                ->first();

            $plan = $subscription?->plan;

            $counter = UsageCounter::query()->firstOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'metric_key' => $metricKey,
                    'period_key' => $periodKey,
                ],
                ['quantity' => 0],
            );

            $counter = UsageCounter::query()->whereKey($counter->id)->lockForUpdate()->firstOrFail();
            $counter->quantity = (int) $counter->quantity + $quantity;
            $counter->save();

            $limit = null;
            $withinLimits = true;

            if (is_array($plan?->limits) && array_key_exists($metricKey, $plan->limits)) {
                $limit = (int) $plan->limits[$metricKey];
                $withinLimits = $counter->quantity <= $limit;
            }

            return [
                'counter' => $counter->fresh(),
                'plan' => $plan,
                'within_limits' => $withinLimits,
                'limit' => $limit,
            ];
        });
    }
}
