<?php

namespace App\Console\Commands;

use App\Jobs\FetchExternalResourceJob;
use App\Models\ConnectionSyncProfile;
use App\Models\Order;
use App\Models\RawResourceSnapshot;
use Illuminate\Console\Command;

class BackfillMercadoLibreShipmentsCommand extends Command
{
    protected $signature = 'sync:backfill-meli-shipments
                            {--connection= : Limit to a single connection ID}
                            {--workspace= : Limit to a workspace ID}
                            {--limit=500 : Max orders to process}
                            {--dry-run : List shipment IDs without dispatching}';

    protected $description = 'Enqueue shipment fetches for Mercado Libre orders that already have shipping.id';

    public function handle(): int
    {
        $query = Order::query()
            ->whereHas('connection', fn ($q) => $q->where('provider', 'mercadolibre'))
            ->orderBy('id');

        if ($this->option('connection')) {
            $query->where('connection_id', (int) $this->option('connection'));
        }

        if ($this->option('workspace')) {
            $query->where('workspace_id', (int) $this->option('workspace'));
        }

        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $queued = 0;
        $skipped = 0;
        $enabledProfiles = [];

        $query->limit($limit)->each(function (Order $order) use ($dryRun, &$queued, &$skipped, &$enabledProfiles) {
            $shippingId = $this->resolveShippingId($order);
            if ($shippingId === null) {
                $skipped++;

                return;
            }

            if (! isset($enabledProfiles[$order->connection_id])) {
                $this->ensureShipmentsProfileEnabled((int) $order->connection_id, (int) $order->workspace_id);
                $enabledProfiles[$order->connection_id] = true;
            }

            if ($dryRun) {
                $this->line("Would fetch shipment {$shippingId} for order {$order->external_order_id}");
                $queued++;

                return;
            }

            FetchExternalResourceJob::dispatch(
                (int) $order->workspace_id,
                (int) $order->connection_id,
                'shipment',
                $shippingId,
            );
            $queued++;
        });

        $verb = $dryRun ? 'Matched' : 'Queued';
        $this->info("{$verb} {$queued} shipment fetch(es); skipped {$skipped} order(s) without shipping.id.");

        return self::SUCCESS;
    }

    private function resolveShippingId(Order $order): ?string
    {
        $metaId = $order->meta['shipping']['id'] ?? null;
        if ($metaId !== null && $metaId !== '') {
            return (string) $metaId;
        }

        if ($order->raw_snapshot_id === null) {
            return null;
        }

        $snapshot = RawResourceSnapshot::query()->find($order->raw_snapshot_id);
        if ($snapshot === null) {
            return null;
        }

        $payloadId = $snapshot->payload['shipping']['id'] ?? null;
        if ($payloadId === null || $payloadId === '') {
            return null;
        }

        return (string) $payloadId;
    }

    private function ensureShipmentsProfileEnabled(int $connectionId, int $workspaceId): void
    {
        $profile = ConnectionSyncProfile::query()->firstOrNew([
            'connection_id' => $connectionId,
            'resource_key' => 'shipments',
        ]);

        $profile->workspace_id = $workspaceId;
        $profile->enabled = true;

        if (! $profile->exists) {
            $profile->config = ['include' => ['raw_snapshot' => true]];
        }

        $profile->save();
    }
}
