<?php

namespace App\Console\Commands;

use App\Domain\PostSale\Actions\ResolveOrderPostSaleOutcome;
use App\Domain\Returns\Actions\ProjectReturnCaseFromClaim;
use App\Models\Claim;
use App\Models\Order;
use Illuminate\Console\Command;

class PostSaleRelinkClaimsCommand extends Command
{
    protected $signature = 'postsale:relink-claims {--workspace=} {--connection=}';

    protected $description = 'Link orphan claims to orders by resource_external_id and refresh return cases';

    public function handle(
        ResolveOrderPostSaleOutcome $resolveOutcome,
        ProjectReturnCaseFromClaim $projectReturnCase,
    ): int {
        $workspaceId = $this->option('workspace');
        $connectionId = $this->option('connection');

        $ordersQuery = Order::query()->orderBy('id');
        if ($workspaceId !== null && $workspaceId !== '') {
            $ordersQuery->where('workspace_id', (int) $workspaceId);
        }
        if ($connectionId !== null && $connectionId !== '') {
            $ordersQuery->where('connection_id', (int) $connectionId);
        }

        $orderMap = [];
        foreach ($ordersQuery->get(['id', 'workspace_id', 'connection_id', 'external_order_id']) as $order) {
            $orderMap[$order->connection_id.'|'.$order->external_order_id] = $order;
        }

        $claimsQuery = Claim::query()
            ->whereNull('order_id')
            ->where('resource', 'order')
            ->whereNotNull('resource_external_id');
        if ($workspaceId !== null && $workspaceId !== '') {
            $claimsQuery->where('workspace_id', (int) $workspaceId);
        }
        if ($connectionId !== null && $connectionId !== '') {
            $claimsQuery->where('connection_id', (int) $connectionId);
        }

        $linked = 0;
        $touched = [];
        foreach ($claimsQuery->cursor() as $claim) {
            $key = $claim->connection_id.'|'.$claim->resource_external_id;
            if (! isset($orderMap[$key])) {
                continue;
            }
            $order = $orderMap[$key];
            $claim->forceFill(['order_id' => $order->id])->save();
            $linked++;
            $touched[$order->id] = true;
        }

        foreach (array_keys($touched) as $orderId) {
            $order = Order::query()->with('lines.variant')->find($orderId);
            if ($order === null) {
                continue;
            }
            $resolveOutcome->apply($order);
            $projectReturnCase->executeForOrder($order->fresh(['lines.variant']) ?? $order);
        }

        $this->info("Linked {$linked} claims across ".count($touched).' orders.');

        return self::SUCCESS;
    }
}
