<?php

namespace App\Http\Controllers;

use App\Domain\Outbound\Actions\EnqueueChannelStockSync;
use App\Domain\Shared\Support\TenantContext;
use App\Jobs\PushOutboundCommandJob;
use App\Models\ChannelListing;
use App\Models\ChannelListingVariant;
use App\Models\Connection;
use App\Models\InventoryBalance;
use App\Models\OutboundCommand;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OutboundStockSyncController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'connection_id' => ['nullable', 'integer'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $workspace = Workspace::query()->find($workspaceId);
        $dryRun = array_key_exists('dry_run', $data)
            ? (bool) $data['dry_run']
            : (bool) ($workspace?->outbound_dry_run ?? true);

        $connectionQuery = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider', 'mercadolibre')
            ->where('status', 'active');

        if (! empty($data['connection_id'])) {
            $connectionQuery->whereKey((int) $data['connection_id']);
        }

        $connection = $connectionQuery->first();
        abort_unless($connection !== null, 422, 'No active Mercado Libre connection.');

        $listings = ChannelListing::query()
            ->where('workspace_id', $workspaceId)
            ->where('connection_id', $connection->id)
            ->whereNotNull('external_item_id')
            ->with('variants')
            ->limit(100)
            ->get();

        $enqueued = 0;
        $enqueue = app(EnqueueChannelStockSync::class);

        foreach ($listings as $listing) {
            $matchedVariants = $listing->variants
                ->filter(fn (ChannelListingVariant $clv) => $clv->variant_id !== null)
                ->unique('variant_id');

            if ($matchedVariants->isEmpty()) {
                // Unmatched listing: push 0 at item level
                $command = OutboundCommand::query()->create([
                    'workspace_id' => $workspaceId,
                    'connection_id' => $connection->id,
                    'command_type' => 'stock_update',
                    'status' => 'pending',
                    'dry_run' => $dryRun,
                    'payload' => [
                        'external_item_id' => $listing->external_item_id,
                        'available_quantity' => 0,
                        'channel_listing_id' => $listing->id,
                    ],
                    'idempotency_key' => 'stock:'.$listing->id.':'.Str::uuid()->toString(),
                    'available_at' => now(),
                ]);

                PushOutboundCommandJob::dispatch(
                    (int) $workspaceId,
                    (int) $connection->id,
                    (int) $command->id,
                );
                $enqueued++;

                continue;
            }

            foreach ($matchedVariants as $clv) {
                $enqueued += $enqueue->execute(
                    (int) $workspaceId,
                    (int) $clv->variant_id,
                    $dryRun,
                );
            }
        }

        return redirect()
            ->back()
            ->with('success', "Enqueued {$enqueued} stock sync command(s) (dry_run=".($dryRun ? 'true' : 'false').').');
    }

    /**
     * Sum available qty across all warehouses for a variant.
     */
    public static function sumAvailableQuantity(int $workspaceId, int $variantId): int
    {
        $balances = InventoryBalance::query()
            ->where('workspace_id', $workspaceId)
            ->whereHas('inventoryItem', fn ($q) => $q->where('variant_id', $variantId))
            ->get();

        if ($balances->isEmpty()) {
            return 0;
        }

        $total = '0';
        foreach ($balances as $balance) {
            $total = bcadd($total, (string) $balance->quantity_available, 6);
        }

        return (int) max(0, (float) $total);
    }
}
