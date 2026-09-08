<?php

namespace App\Console\Commands;

use App\Domain\Sales\Support\OrderProviderDates;
use App\Models\Order;
use App\Models\RawResourceSnapshot;
use Illuminate\Console\Command;

/**
 * Recompute orders.ordered_at / paid_at from raw ML snapshots (UTC-safe).
 *
 * Does not wipe the database — only UPDATEs mismatched timestamps.
 */
class BackfillOrderDatesCommand extends Command
{
    protected $signature = 'sync:backfill-order-dates
                            {--connection= : Limit to a single connection ID}
                            {--workspace= : Limit to a workspace ID}
                            {--limit=0 : Max orders to process (0 = all)}
                            {--dry-run : Report mismatches without updating}';

    protected $description = 'Fix order ordered_at/paid_at from raw snapshot date_created / payments.date_approved / date_closed';

    public function handle(): int
    {
        $query = Order::query()
            ->whereNotNull('raw_snapshot_id')
            ->orderBy('id');

        if ($this->option('connection')) {
            $query->where('connection_id', (int) $this->option('connection'));
        }

        if ($this->option('workspace')) {
            $query->where('workspace_id', (int) $this->option('workspace'));
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $dryRun = (bool) $this->option('dry-run');
        $checked = 0;
        $updated = 0;
        $skipped = 0;

        $query->each(function (Order $order) use ($dryRun, &$checked, &$updated, &$skipped) {
            $checked++;
            $snapshot = RawResourceSnapshot::query()->find($order->raw_snapshot_id);
            if ($snapshot === null || ! is_array($snapshot->payload)) {
                $skipped++;

                return;
            }

            $payload = $snapshot->payload;
            $orderedAt = OrderProviderDates::orderedAt($payload);
            $paidAt = OrderProviderDates::paidAt($payload);

            if ($orderedAt === null) {
                $skipped++;

                return;
            }

            $orderedChanged = $order->ordered_at?->equalTo($orderedAt) !== true;
            $paidChanged = ($order->paid_at === null && $paidAt !== null)
                || ($order->paid_at !== null && $paidAt === null)
                || ($order->paid_at !== null && $paidAt !== null && ! $order->paid_at->equalTo($paidAt));

            if (! $orderedChanged && ! $paidChanged) {
                return;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    'Would update order %s: ordered_at %s → %s; paid_at %s → %s',
                    $order->external_order_id,
                    $order->ordered_at?->toIso8601String() ?? 'null',
                    $orderedAt->toIso8601String(),
                    $order->paid_at?->toIso8601String() ?? 'null',
                    $paidAt?->toIso8601String() ?? 'null',
                ));
                $updated++;

                return;
            }

            $order->forceFill([
                'ordered_at' => $orderedAt,
                'paid_at' => $paidAt,
            ])->save();
            $updated++;
        });

        $verb = $dryRun ? 'Would update' : 'Updated';
        $this->info("Checked {$checked}; {$verb} {$updated}; skipped {$skipped}.");

        return self::SUCCESS;
    }
}
