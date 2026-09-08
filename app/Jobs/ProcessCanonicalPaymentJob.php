<?php

namespace App\Jobs;

use App\Domain\Cash\Actions\UpsertMarketplacePayment;
use App\Domain\Finance\Actions\PromoteFinancialEventsToRealized;
use App\Domain\Finance\Actions\RefreshExpectedOrderFinance;
use App\Domain\Integrations\Actions\ResolveSyncProfile;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\Connection;
use App\Models\Order;
use App\Models\RawResourceSnapshot;

final class ProcessCanonicalPaymentJob extends TenantAwareJob
{
    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly int $snapshotId,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('critical-sync');
    }

    protected function handleForTenant(): void
    {
        $connection = Connection::query()->findOrFail($this->connectionId);
        if (! app(ResolveSyncProfile::class)->isEnabled($connection, 'payments')) {
            return;
        }

        $snapshot = RawResourceSnapshot::query()->findOrFail($this->snapshotId);
        $payload = is_array($snapshot->payload) ? $snapshot->payload : [];
        $paymentId = (string) ($payload['id'] ?? $snapshot->external_id);
        if ($paymentId === '') {
            return;
        }

        $orderExternalId = null;
        $orderNode = $payload['order'] ?? null;
        if (is_array($orderNode) && isset($orderNode['id'])) {
            $orderExternalId = (string) $orderNode['id'];
        } elseif (isset($payload['order_id'])) {
            $orderExternalId = (string) $payload['order_id'];
        } elseif (isset($payload['external_reference'])) {
            $orderExternalId = (string) $payload['external_reference'];
        }

        $order = null;
        if ($orderExternalId !== null && $orderExternalId !== '') {
            $order = Order::query()
                ->where('connection_id', $this->connectionId)
                ->where('external_order_id', $orderExternalId)
                ->first();
        }

        if ($order === null) {
            // Payment arrived before order projection — keep snapshot only.
            return;
        }

        app(UpsertMarketplacePayment::class)->execute(
            $order,
            $paymentId,
            $payload,
            $snapshot->id,
            ['triggered_by' => 'payment_webhook'],
        );

        if (app(ResolveSyncProfile::class)->isEnabled($connection, 'order_fees')) {
            app(RefreshExpectedOrderFinance::class)->execute($order);
        }

        app(PromoteFinancialEventsToRealized::class)->execute($order->fresh());
    }
}
