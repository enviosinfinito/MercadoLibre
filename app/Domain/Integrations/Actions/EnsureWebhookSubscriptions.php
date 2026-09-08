<?php

namespace App\Domain\Integrations\Actions;

use App\Models\Connection;
use App\Models\ConnectionCapability;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Idempotent Mercado Libre webhook subscription ensure.
 * Marks connection_capabilities; HTTP call is stubbed unless MELI_WEBHOOKS_STUB=false.
 */
final class EnsureWebhookSubscriptions
{
    public function execute(Connection $connection): ConnectionCapability
    {
        $topics = ['orders_v2', 'orders', 'items', 'payments', 'shipments', 'questions', 'messages', 'claims', 'claims_actions'];

        if (! filter_var(env('MELI_WEBHOOKS_STUB', true), FILTER_VALIDATE_BOOLEAN)) {
            $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
            // Real ML app webhook config is typically managed in the developer console;
            // this stub posts a no-op ping for observability when stub mode is off.
            Http::acceptJson()->post($base.'/applications/'.config('connectors.mercadolibre.client_id').'/webhooks', [
                'topics' => $topics,
                'connection_id' => $connection->id,
            ]);
        } else {
            Log::info('mercadolibre.webhooks.ensure_stub', [
                'workspace_id' => $connection->workspace_id,
                'connection_id' => $connection->id,
                'topics' => $topics,
            ]);
        }

        return ConnectionCapability::query()->updateOrCreate(
            [
                'connection_id' => $connection->id,
                'capability_key' => 'webhooks',
            ],
            [
                'workspace_id' => $connection->workspace_id,
                'enabled' => true,
                'meta' => [
                    'topics' => $topics,
                    'ensured_at' => now()->toIso8601String(),
                    'stub' => filter_var(env('MELI_WEBHOOKS_STUB', true), FILTER_VALIDATE_BOOLEAN),
                ],
            ],
        );
    }
}
