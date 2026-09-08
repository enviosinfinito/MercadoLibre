<?php

namespace App\Jobs;

use App\Integrations\Contracts\ConnectorRegistry;
use App\Integrations\Contracts\Dto\PushRequest;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\Connection;
use App\Models\OutboundCommand;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PushOutboundCommandJob extends TenantAwareJob
{
    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly int $outboundCommandId,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('outbound');
    }

    protected function handleForTenant(): void
    {
        $command = OutboundCommand::query()->findOrFail($this->outboundCommandId);
        $command->attempts = ((int) $command->attempts) + 1;
        $command->status = 'processing';
        $command->save();

        try {
            $connection = Connection::query()->findOrFail($this->connectionId);
            /** @var ConnectorRegistry $registry */
            $registry = app(ConnectorRegistry::class);
            $connector = $registry->get($connection->provider);

            $payload = $command->payload ?? [];
            $resource = match ($command->command_type) {
                'stock_update' => 'item_stock',
                'seller_warehouse_stock' => 'seller_warehouse_stock',
                'price_update' => 'item_price',
                'listing_status' => 'item_status',
                'listing_update' => 'item_update',
                default => (string) ($payload['resource'] ?? $command->command_type),
            };

            $accessToken = $this->resolveAccessToken($connection);

            $pushPayload = array_merge($payload, [
                'dry_run' => (bool) $command->dry_run,
                'access_token' => $accessToken,
                'command_type' => $command->command_type,
            ]);

            $result = $connector->push(new PushRequest(
                resource: $resource,
                payload: $pushPayload,
            ));

            $command->status = 'completed';
            $command->processed_at = now();
            $command->result = $result->payload;
            $command->last_error_redacted = null;
            $command->save();

            if (in_array($command->command_type, ['stock_update', 'seller_warehouse_stock'], true)
                && isset($payload['channel_listing_variant_id'])
            ) {
                \App\Models\ChannelListingVariant::query()
                    ->whereKey((int) $payload['channel_listing_variant_id'])
                    ->update(['stock_synced_at' => now()]);
            }

            if ($command->command_type === 'price_update' && isset($payload['channel_listing_variant_id'])) {
                \App\Models\ChannelListingVariant::query()
                    ->whereKey((int) $payload['channel_listing_variant_id'])
                    ->update(['price_synced_at' => now()]);
            }

            Log::info('outbound.push.completed', [
                'command_id' => $command->id,
                'command_type' => $command->command_type,
                'dry_run' => $command->dry_run,
            ]);
        } catch (Throwable $e) {
            $command->status = 'failed';
            $command->last_error_redacted = mb_substr($e->getMessage(), 0, 500);
            $command->processed_at = now();
            $command->result = ['ok' => false, 'error' => 'push_failed'];
            $command->save();

            throw $e;
        }
    }

    private function resolveAccessToken(Connection $connection): ?string
    {
        try {
            return app(\App\Domain\Integrations\Actions\EnsureFreshConnectionToken::class)
                ->execute($connection);
        } catch (Throwable) {
            return null;
        }
    }
}
