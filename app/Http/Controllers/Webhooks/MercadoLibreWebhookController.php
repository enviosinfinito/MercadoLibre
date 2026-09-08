<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Integrations\Actions\RecordSyncHttpLog;
use App\Http\Controllers\Controller;
use App\Integrations\MercadoLibre\Support\MercadoLibreWebhookAuthenticator;
use App\Integrations\MercadoLibre\Support\MercadoLibreWebhookTopic;
use App\Integrations\Support\SyncHttpLogContext;
use App\Jobs\ProcessWebhookIngressJob;
use App\Models\Connection;
use App\Models\RawWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MercadoLibreWebhookController extends Controller
{
    private const MAX_BYTES = 1_048_576; // 1 MiB

    public function __invoke(Request $request, MercadoLibreWebhookAuthenticator $authenticator): JsonResponse
    {
        $raw = $request->getContent();
        if (strlen($raw) > self::MAX_BYTES) {
            return response()->json(['error' => 'payload_too_large'], 413);
        }

        $payload = $request->json()->all() ?: (json_decode($raw, true) ?: []);

        if (! $authenticator->authenticate($request, is_array($payload) ? $payload : [])) {
            Log::warning('webhook.meli.auth_failed', [
                'application_id' => $payload['application_id'] ?? null,
                'topic' => $payload['topic'] ?? null,
                'has_x_signature' => $request->headers->has('x-signature'),
                'has_secret_query' => $request->query->has('secret'),
            ]);

            return response()->json(['error' => 'unauthorized'], 401);
        }

        $userId = isset($payload['user_id']) ? (string) $payload['user_id'] : null;
        $topic = $payload['topic'] ?? null;
        $resource = $payload['resource'] ?? null;
        $externalResourceId = null;

        if (is_string($resource) && preg_match('#/(?:orders|items|shipments|questions|claims|payments|collections)/([^/?]+)#', $resource, $m)) {
            $externalResourceId = $m[1];
        }

        $dedupeKey = hash('sha256', implode('|', [
            'mercadolibre',
            (string) $userId,
            (string) $topic,
            (string) $resource,
            (string) ($payload['_id'] ?? $payload['id'] ?? $raw),
        ]));

        $connection = null;
        if ($userId !== null) {
            $connection = Connection::query()
                ->where('provider', 'mercadolibre')
                ->where('external_user_id', $userId)
                ->where('status', 'active')
                ->first();
        }

        try {
            $event = RawWebhookEvent::query()->firstOrCreate(
                ['dedupe_key' => $dedupeKey],
                [
                    'workspace_id' => $connection?->workspace_id,
                    'connection_id' => $connection?->id,
                    'provider' => 'mercadolibre',
                    'topic' => is_string($topic) ? $topic : null,
                    'external_user_id' => $userId,
                    'external_resource_id' => $externalResourceId,
                    'payload' => $payload,
                    'headers_redacted' => $this->redactHeaders($request->headers->all()),
                    'status' => 'received',
                    'received_at' => now(),
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('webhook.meli.store_failed', ['error' => $e->getMessage()]);

            return response()->json(['ok' => true]);
        }

        if ($connection && $event->wasRecentlyCreated) {
            try {
                SyncHttpLogContext::bind(
                    workspaceId: (int) $connection->workspace_id,
                    connectionId: (int) $connection->id,
                    provider: 'mercadolibre',
                    correlationId: (string) Str::uuid(),
                );

                app(RecordSyncHttpLog::class)->recordInbound([
                    'workspace_id' => $connection->workspace_id,
                    'connection_id' => $connection->id,
                    'provider' => 'mercadolibre',
                    'method' => 'POST',
                    'url' => $request->fullUrl(),
                    'endpoint_group' => MercadoLibreWebhookTopic::endpointGroup(
                        is_string($topic) ? $topic : null
                    ),
                    'response_status' => 200,
                    'latency_ms' => 0,
                    'request_headers_redacted' => $event->headers_redacted,
                    'request_body_redacted' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'response_body_redacted' => '{"ok":true}',
                    'request_bytes' => strlen($raw),
                    'response_bytes' => 11,
                ]);
            } catch (\Throwable $e) {
                Log::warning('webhook.meli.http_log_failed', ['error' => $e->getMessage()]);
            } finally {
                SyncHttpLogContext::clear();
            }

            ProcessWebhookIngressJob::dispatch(
                (int) $connection->workspace_id,
                (int) $connection->id,
                (int) $event->id,
            );
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, array<int, string|null>>  $headers
     * @return array<string, mixed>
     */
    private function redactHeaders(array $headers): array
    {
        $allowed = ['content-type', 'user-agent', 'x-request-id'];
        $out = [];

        foreach ($headers as $key => $values) {
            $lower = strtolower($key);
            if (in_array($lower, $allowed, true)) {
                $out[$lower] = $values[0] ?? null;
            }
        }

        return $out;
    }
}
