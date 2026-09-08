<?php

namespace App\Domain\PostSale\Actions;

use App\Models\Claim;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Support\Carbon;

final class UpsertCanonicalClaim
{
    /**
     * @param  array{
     *   external_claim_id: string,
     *   order_id?: int|null,
     *   type?: string|null,
     *   stage?: string|null,
     *   status?: string|null,
     *   reason?: string|null,
     *   reason_id?: string|null,
     *   resource?: string|null,
     *   resource_external_id?: string|null,
     *   notes?: string|null,
     *   opened_at?: Carbon|string|null,
     *   closed_at?: Carbon|string|null,
     *   raw_snapshot_id?: int|null,
     *   meta?: array<string, mixed>|null,
     * }  $payload
     */
    public function execute(int $workspaceId, int $connectionId, array $payload): Claim
    {
        $externalId = (string) ($payload['external_claim_id'] ?? '');
        if ($externalId === '') {
            throw new \InvalidArgumentException('external_claim_id is required.');
        }

        $orderId = $payload['order_id'] ?? null;
        if ($orderId === null) {
            $orderId = $this->resolveOrderId(
                $workspaceId,
                $connectionId,
                isset($payload['resource']) ? (string) $payload['resource'] : null,
                isset($payload['resource_external_id']) ? (string) $payload['resource_external_id'] : null,
            );
        }

        $claim = Claim::query()->updateOrCreate(
            [
                'connection_id' => $connectionId,
                'external_claim_id' => $externalId,
            ],
            [
                'workspace_id' => $workspaceId,
                'order_id' => $orderId,
                'type' => $payload['type'] ?? null,
                'stage' => $payload['stage'] ?? null,
                'status' => $this->mapStatus((string) ($payload['status'] ?? 'opened')),
                'reason' => $payload['reason'] ?? ($payload['reason_id'] ?? null),
                'reason_id' => $payload['reason_id'] ?? null,
                'resource' => $payload['resource'] ?? null,
                'resource_external_id' => $payload['resource_external_id'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'opened_at' => $payload['opened_at'] ?? null,
                'closed_at' => $payload['closed_at'] ?? null,
                'raw_snapshot_id' => $payload['raw_snapshot_id'] ?? null,
                'meta' => $payload['meta'] ?? null,
            ],
        );

        app(ApplyOrderPostSaleEffects::class)->execute(
            $claim->order_id !== null ? (int) $claim->order_id : null,
        );

        return $claim;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{
     *   external_claim_id: string,
     *   type: string|null,
     *   stage: string|null,
     *   status: string,
     *   reason: string|null,
     *   reason_id: string|null,
     *   resource: string|null,
     *   resource_external_id: string|null,
     *   opened_at: Carbon|null,
     *   closed_at: Carbon|null,
     *   meta: array<string, mixed>,
     * }
     */
    public function mapFromProviderPayload(array $raw, ?string $fallbackExternalId = null): array
    {
        $externalId = (string) ($raw['id'] ?? $fallbackExternalId ?? '');
        $status = $this->mapStatus((string) ($raw['status'] ?? 'opened'));
        $reasonId = isset($raw['reason_id']) ? (string) $raw['reason_id'] : null;
        $resource = isset($raw['resource']) ? (string) $raw['resource'] : null;
        $resourceId = isset($raw['resource_id']) ? (string) $raw['resource_id'] : null;

        $resolution = is_array($raw['resolution'] ?? null) ? $raw['resolution'] : null;
        $openedAt = $this->parseDate($raw['date_created'] ?? null);
        $closedAt = null;
        if ($status === 'closed') {
            $closedAt = $this->parseDate($resolution['date_created'] ?? null)
                ?? $this->parseDate($raw['last_updated'] ?? null);
        }

        return [
            'external_claim_id' => $externalId,
            'type' => isset($raw['type']) ? (string) $raw['type'] : null,
            'stage' => isset($raw['stage']) ? (string) $raw['stage'] : null,
            'status' => $status,
            'reason' => $reasonId,
            'reason_id' => $reasonId,
            'resource' => $resource !== '' ? $resource : null,
            'resource_external_id' => $resourceId !== '' && $resourceId !== null ? $resourceId : null,
            'opened_at' => $openedAt,
            'closed_at' => $closedAt,
            'meta' => array_filter([
                'players' => is_array($raw['players'] ?? null) ? $raw['players'] : null,
                'resolution' => $resolution,
                'site_id' => $raw['site_id'] ?? null,
                'labels' => $raw['labels'] ?? null,
                'parent_id' => $raw['parent_id'] ?? null,
                'fulfilled' => $raw['fulfilled'] ?? null,
                'quantity_type' => $raw['quantity_type'] ?? null,
                'last_updated' => $raw['last_updated'] ?? null,
            ], static fn ($v) => $v !== null),
        ];
    }

    public function mapStatus(string $providerStatus): string
    {
        $normalized = strtolower(trim($providerStatus));

        return match ($normalized) {
            'opened', 'open' => 'opened',
            'closed', 'close' => 'closed',
            default => $normalized !== '' ? $normalized : 'opened',
        };
    }

    public function resolveOrderId(
        int $workspaceId,
        int $connectionId,
        ?string $resource,
        ?string $resourceExternalId,
    ): ?int {
        if ($resource === null || $resource === '' || $resourceExternalId === null || $resourceExternalId === '') {
            return null;
        }

        if ($resource === 'order') {
            $order = Order::query()
                ->where('workspace_id', $workspaceId)
                ->where('connection_id', $connectionId)
                ->where('external_order_id', $resourceExternalId)
                ->first();

            return $order?->id;
        }

        if ($resource === 'shipment') {
            $shipment = Shipment::query()
                ->where('workspace_id', $workspaceId)
                ->where('connection_id', $connectionId)
                ->where('external_shipment_id', $resourceExternalId)
                ->first();

            return $shipment?->order_id;
        }

        return null;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        return \App\Domain\Shared\Support\ProviderDateTime::parseUtc($value);
    }
}
