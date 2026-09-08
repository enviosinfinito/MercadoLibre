<?php

namespace App\Jobs;

use App\Domain\Fulfillment\Actions\UpsertCanonicalShipment;
use App\Domain\Integrations\Actions\ResolveSyncProfile;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\Connection;
use App\Models\RawResourceSnapshot;
use Illuminate\Support\Carbon;

final class ProcessCanonicalShipmentJob extends TenantAwareJob
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
        $snapshot = RawResourceSnapshot::query()->findOrFail($this->snapshotId);
        $payload = $snapshot->payload;
        $connection = Connection::query()->findOrFail($this->connectionId);
        $resolve = app(ResolveSyncProfile::class);

        if (! $resolve->isEnabled($connection, 'shipments')) {
            return;
        }

        $history = is_array($payload['status_history'] ?? null) ? $payload['status_history'] : [];
        $shippedAt = $this->parseDate($history['date_shipped'] ?? null);
        $deliveredAt = $this->parseDate($history['date_delivered'] ?? null);

        $externalOrderId = null;
        if (isset($payload['order_id']) && $payload['order_id'] !== '' && $payload['order_id'] !== null) {
            $externalOrderId = (string) $payload['order_id'];
        }

        $upsert = app(UpsertCanonicalShipment::class);

        $upsert->execute($this->workspaceId, $this->connectionId, [
            'external_shipment_id' => (string) ($payload['id'] ?? $snapshot->external_id),
            'status' => (string) ($payload['status'] ?? 'pending'),
            'carrier' => isset($payload['tracking_method']) ? (string) $payload['tracking_method'] : null,
            'tracking_number' => isset($payload['tracking_number']) ? (string) $payload['tracking_number'] : null,
            'shipped_at' => $shippedAt,
            'delivered_at' => $deliveredAt,
            'external_order_id' => $externalOrderId,
            'meta' => $upsert->buildMetaFromProviderPayload($payload),
        ]);
    }

    private function parseDate(mixed $value): ?Carbon
    {
        return \App\Domain\Shared\Support\ProviderDateTime::parseUtc($value);
    }
}
