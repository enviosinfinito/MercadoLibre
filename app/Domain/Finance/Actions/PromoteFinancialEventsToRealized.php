<?php

namespace App\Domain\Finance\Actions;

use App\Models\FinancialEvent;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Stub: when payment is approved, copy expected financial events into current (realized) stage.
 */
final class PromoteFinancialEventsToRealized
{
    public function execute(Order $order): int
    {
        if ($order->paid_at === null && ! in_array($order->status, ['paid', 'payment_approved', 'approved'], true)) {
            return 0;
        }

        return (int) DB::transaction(function () use ($order) {
            $expected = FinancialEvent::query()
                ->where('order_id', $order->id)
                ->where('stage', 'expected')
                ->get();

            $created = 0;

            foreach ($expected as $event) {
                $already = FinancialEvent::query()
                    ->where('order_id', $order->id)
                    ->where('order_line_id', $event->order_line_id)
                    ->where('event_type', $event->event_type)
                    ->where('stage', 'current')
                    ->exists();

                if ($already) {
                    continue;
                }

                FinancialEvent::query()->create([
                    'workspace_id' => $event->workspace_id,
                    'connection_id' => $event->connection_id,
                    'order_id' => $event->order_id,
                    'order_line_id' => $event->order_line_id,
                    'calculation_version_id' => $event->calculation_version_id,
                    'event_type' => $event->event_type,
                    'stage' => 'current',
                    'amount' => $event->amount,
                    'currency_code' => $event->currency_code,
                    'reporting_amount' => $event->reporting_amount,
                    'reporting_currency' => $event->reporting_currency,
                    'occurred_at' => $order->paid_at ?? now(),
                    'provenance' => array_merge($event->provenance ?? [], [
                        'promoted_from' => 'expected',
                        'promoted_at' => now()->toIso8601String(),
                    ]),
                ]);

                $created++;
            }

            return $created;
        });
    }
}
