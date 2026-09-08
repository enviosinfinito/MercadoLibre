<?php

namespace App\Jobs;

use App\Domain\Integrations\Actions\ResolveSyncProfile;
use App\Integrations\Contracts\ConnectorRegistry;
use App\Integrations\Contracts\Dto\WebhookPayload;
use App\Integrations\Sync\SyncResourceCatalog;
use App\Jobs\Concerns\TenantAwareJob;
use App\Models\Connection;
use App\Models\RawWebhookEvent;
use Illuminate\Support\Facades\Log;

final class ProcessWebhookIngressJob extends TenantAwareJob
{
    public function __construct(
        int $workspaceId,
        int $connectionId,
        public readonly int $rawWebhookEventId,
    ) {
        parent::__construct($workspaceId, $connectionId);
        $this->onQueue('webhook-ingress');
    }

    protected function handleForTenant(): void
    {
        $event = RawWebhookEvent::query()->findOrFail($this->rawWebhookEventId);

        if ($event->status === 'processed') {
            return;
        }

        /** @var ConnectorRegistry $registry */
        $registry = app(ConnectorRegistry::class);
        $connector = $registry->get($event->provider);

        $parsed = $connector->parseWebhook(new WebhookPayload(
            headers: $event->headers_redacted ?? [],
            body: $event->payload,
        ));

        $connection = Connection::query()->findOrFail($this->connectionId);
        $resolve = app(ResolveSyncProfile::class);
        $catalog = app(SyncResourceCatalog::class);

        $dispatched = 0;
        foreach ($parsed->events as $item) {
            $resourceType = $item['resource_type'] ?? $parsed->type;
            $externalId = (string) ($item['external_id'] ?? '');

            if ($externalId === '') {
                continue;
            }

            $resourceKey = $catalog->resolveResourceKey((string) $resourceType);
            if (! $resolve->isEnabled($connection, $resourceKey)) {
                Log::info('webhook.ingress.skipped_disabled', [
                    'raw_webhook_event_id' => $event->id,
                    'resource_key' => $resourceKey,
                    'external_id' => $externalId,
                ]);

                continue;
            }

            FetchExternalResourceJob::dispatch(
                $this->workspaceId,
                $this->connectionId,
                (string) $resourceType,
                $externalId,
            );
            $dispatched++;
        }

        $event->status = 'processed';
        $event->processed_at = now();
        $event->save();

        Log::info('webhook.ingress.processed', [
            'raw_webhook_event_id' => $event->id,
            'provider' => $event->provider,
            'events' => count($parsed->events),
            'dispatched' => $dispatched,
        ]);
    }
}
