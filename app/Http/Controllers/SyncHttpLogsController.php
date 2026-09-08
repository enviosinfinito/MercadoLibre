<?php

namespace App\Http\Controllers;

use App\Domain\Platform\DiagnosticHttpLogging;
use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Concerns\RespondsWithJsonPaginator;
use App\Http\Filters\SyncHttpLogs\SyncHttpLogFilterRegistry;
use App\Http\Requests\SyncHttpLogs\IndexFilterRequest;
use App\Integrations\MercadoLibre\Support\MercadoLibreWebhookTopic;
use App\Models\Connection;
use App\Models\SyncHttpLog;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class SyncHttpLogsController extends Controller
{
    use RespondsWithJsonPaginator;

    public function index(IndexFilterRequest $request, SyncHttpLogFilterRegistry $filterRegistry): Response|JsonResponse
    {
        $workspaceId = TenantContext::id();
        $filters = $request->filters();

        $query = SyncHttpLog::query()
            ->where('workspace_id', $workspaceId)
            ->with(['connection:id,provider,external_user_id,display_name,color'])
            ->orderByDesc('id');

        $filterRegistry->apply($query, $filters);

        $logs = $query
            ->paginate(25)
            ->withQueryString()
            ->through(fn (SyncHttpLog $log) => $this->transformListRow($log));

        if ($this->wantsJsonWithoutInertia($request)) {
            return $this->jsonPaginator($logs);
        }

        $connections = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('id')
            ->get(['id', 'provider', 'external_user_id', 'display_name', 'color']);

        return Inertia::render('SyncHttpLogs/Index', [
            'logs' => $logs,
            'filters' => [
                'http_direction' => $filters['http_direction'] !== '' ? $filters['http_direction'] : null,
                'status' => $filters['status'] !== '' ? $filters['status'] : null,
                'topic' => $filters['topic'] !== '' ? $filters['topic'] : null,
                'q' => $filters['q'] !== '' ? $filters['q'] : null,
                'connection_id' => $filters['connection_id'],
                'from' => $filters['from'] !== '' ? $filters['from'] : null,
                'to' => $filters['to'] !== '' ? $filters['to'] : null,
                'orphan' => $filters['orphan'] ? '1' : null,
                'body_search' => $filters['body_search'] !== '' ? $filters['body_search'] : null,
            ],
            'connections' => $connections,
            'webhook_topics' => [
                ['value' => 'orders_v2', 'label' => MercadoLibreWebhookTopic::label('orders_v2')],
                ['value' => 'orders', 'label' => MercadoLibreWebhookTopic::label('orders')],
                ['value' => 'items', 'label' => MercadoLibreWebhookTopic::label('items')],
                ['value' => 'shipments', 'label' => MercadoLibreWebhookTopic::label('shipments')],
                ['value' => 'payments', 'label' => MercadoLibreWebhookTopic::label('payments')],
                ['value' => 'questions', 'label' => MercadoLibreWebhookTopic::label('questions')],
                ['value' => 'messages', 'label' => MercadoLibreWebhookTopic::label('messages')],
                ['value' => 'claims', 'label' => MercadoLibreWebhookTopic::label('claims')],
                ['value' => 'claims_actions', 'label' => MercadoLibreWebhookTopic::label('claims_actions')],
            ],
            'diagnostic_logging' => app(DiagnosticHttpLogging::class)->status(),
        ]);
    }

    public function indexListUpdates(IndexFilterRequest $request, SyncHttpLogFilterRegistry $filterRegistry): JsonResponse
    {
        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $sinceId = (int) $request->input('since_id', 0);
        if ($sinceId <= 0) {
            return response()->json(['message' => 'since_id requerido'], 422);
        }

        $filters = $request->filters();

        $query = SyncHttpLog::query()
            ->where('workspace_id', $workspaceId)
            ->with(['connection:id,provider,external_user_id,display_name,color'])
            ->where('id', '>', $sinceId)
            ->orderByDesc('id')
            ->limit(25);

        $filterRegistry->apply($query, $filters);

        $rows = $query
            ->get()
            ->map(fn (SyncHttpLog $log) => $this->transformListRow($log))
            ->values();

        return response()->json([
            'data' => $rows,
            'count' => $rows->count(),
        ]);
    }

    public function show(SyncHttpLog $syncHttpLog): JsonResponse
    {
        abort_unless((int) $syncHttpLog->workspace_id === TenantContext::id(), 404);

        $syncHttpLog->load(['connection:id,provider,external_user_id,display_name,site_id,color']);
        $topic = $this->resolveWebhookTopic($syncHttpLog);

        return response()->json([
            'log' => [
                'id' => $syncHttpLog->id,
                'created_at' => $syncHttpLog->created_at?->toIso8601String(),
                'direction' => $syncHttpLog->direction,
                'provider' => $syncHttpLog->provider,
                'method' => $syncHttpLog->method,
                'url' => $syncHttpLog->url,
                'endpoint_group' => $syncHttpLog->endpoint_group,
                'webhook_topic' => $topic,
                'webhook_topic_label' => $topic !== null
                    ? MercadoLibreWebhookTopic::label($topic)
                    : null,
                'response_status' => $syncHttpLog->response_status,
                'latency_ms' => $syncHttpLog->latency_ms,
                'correlation_id' => $syncHttpLog->correlation_id,
                'sync_run_id' => $syncHttpLog->sync_run_id,
                'connection_id' => $syncHttpLog->connection_id,
                'request_bytes' => $syncHttpLog->request_bytes,
                'response_bytes' => $syncHttpLog->response_bytes,
                'request_headers_redacted' => $syncHttpLog->request_headers_redacted,
                'request_body_redacted' => $syncHttpLog->request_body_redacted,
                'response_headers_redacted' => $syncHttpLog->response_headers_redacted,
                'response_body_redacted' => $syncHttpLog->response_body_redacted,
                'error_redacted' => $syncHttpLog->error_redacted,
                'connection' => $syncHttpLog->connection ? [
                    'id' => $syncHttpLog->connection->id,
                    'provider' => $syncHttpLog->connection->provider,
                    'external_user_id' => $syncHttpLog->connection->external_user_id,
                    'display_name' => $syncHttpLog->connection->display_name,
                    'site_id' => $syncHttpLog->connection->site_id,
                    'color' => $syncHttpLog->connection->color,
                ] : null,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformListRow(SyncHttpLog $log): array
    {
        $topic = $this->resolveWebhookTopic($log);

        return [
            'id' => $log->id,
            'created_at' => $log->created_at?->toIso8601String(),
            'direction' => $log->direction,
            'provider' => $log->provider,
            'method' => $log->method,
            'url' => $log->url,
            'endpoint_group' => $log->endpoint_group,
            'webhook_topic' => $topic,
            'webhook_topic_label' => $topic !== null
                ? MercadoLibreWebhookTopic::label($topic)
                : null,
            'response_status' => $log->response_status,
            'latency_ms' => $log->latency_ms,
            'correlation_id' => $log->correlation_id,
            'sync_run_id' => $log->sync_run_id,
            'connection' => $log->connection ? [
                'id' => $log->connection->id,
                'provider' => $log->connection->provider,
                'external_user_id' => $log->connection->external_user_id,
                'display_name' => $log->connection->display_name,
                'color' => $log->connection->color,
            ] : null,
        ];
    }

    private function resolveWebhookTopic(SyncHttpLog $log): ?string
    {
        if ($log->direction !== 'in') {
            return null;
        }

        return MercadoLibreWebhookTopic::fromEndpointGroup($log->endpoint_group);
    }
}
