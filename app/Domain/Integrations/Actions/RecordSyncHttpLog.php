<?php

namespace App\Domain\Integrations\Actions;

use App\Domain\Platform\DiagnosticHttpLogging;
use App\Domain\Shared\Support\TenantContext;
use App\Integrations\Support\SyncHttpLogContext;
use App\Models\SyncHttpLog;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

final class RecordSyncHttpLog
{
    public function __construct(
        private readonly DiagnosticHttpLogging $diagnosticHttpLogging,
    ) {}

    private const MAX_BODY_CHARS = 100_000;

    /**
     * @param  array<string, mixed>|string|null  $requestBody
     * @param  array<string, mixed>  $requestHeaders
     * @param  array<string, mixed>  $overrides
     */
    public function fromResponse(
        string $method,
        string $url,
        array|string|null $requestBody,
        Response $response,
        int $startedHrtime,
        array $requestHeaders = [],
        array $overrides = [],
    ): SyncHttpLog {
        $latencyMs = (int) max(0, (hrtime(true) - $startedHrtime) / 1_000_000);
        $responseBody = $response->body();
        $ctx = SyncHttpLogContext::snapshot();
        $workspaceId = $ctx['workspace_id'] ?? TenantContext::workspaceId();

        return $this->persist(array_merge([
            'workspace_id' => $workspaceId,
            'connection_id' => $ctx['connection_id'],
            'sync_run_id' => $ctx['sync_run_id'],
            'provider' => $ctx['provider'],
            'correlation_id' => $ctx['correlation_id'],
            'direction' => 'out',
            'method' => strtoupper($method),
            'url' => $url,
            'endpoint_group' => $this->endpointGroup($url),
            'response_status' => $response->status(),
            'latency_ms' => $latencyMs,
            'request_headers_redacted' => $this->redactHeaders($requestHeaders),
            'request_body_redacted' => $this->redactBody($requestBody),
            'response_headers_redacted' => $this->redactHeaders($response->headers()),
            'response_body_redacted' => $this->redactBody($responseBody),
            'request_bytes' => $this->byteLength($requestBody),
            'response_bytes' => strlen($responseBody),
            'error_redacted' => $response->failed()
                ? mb_substr($responseBody, 0, 500)
                : null,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function recordInbound(array $attributes): SyncHttpLog
    {
        $ctx = SyncHttpLogContext::snapshot();

        return $this->persist(array_merge([
            'workspace_id' => $ctx['workspace_id'] ?? ($attributes['workspace_id'] ?? null),
            'connection_id' => $ctx['connection_id'] ?? ($attributes['connection_id'] ?? null),
            'sync_run_id' => $ctx['sync_run_id'] ?? null,
            'provider' => $ctx['provider'] ?? ($attributes['provider'] ?? null),
            'correlation_id' => $ctx['correlation_id'] ?? ($attributes['correlation_id'] ?? (string) Str::uuid()),
            'direction' => 'in',
            'method' => 'POST',
            'endpoint_group' => 'webhooks',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persist(array $attributes): SyncHttpLog
    {
        if ((int) ($attributes['workspace_id'] ?? 0) <= 0) {
            // OAuth / early calls may lack tenant context — skip rather than crash.
            return new SyncHttpLog($attributes);
        }

        if (! $this->diagnosticHttpLogging->isEnabled()) {
            return new SyncHttpLog($attributes);
        }

        return SyncHttpLog::query()->create($attributes);
    }

    private function endpointGroup(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        return match (true) {
            str_contains($path, '/oauth/token') => 'oauth',
            str_contains($path, '/items/search') => 'listings.search',
            str_contains($path, '/description') => 'items.description',
            (bool) preg_match('#/items(/|$|\?)#', $path) => 'items',
            str_contains($path, '/orders') => 'orders',
            str_contains($path, '/shipments') => 'shipments',
            str_contains($path, '/questions') => 'questions',
            default => 'other',
        };
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    private function redactHeaders(array $headers): array
    {
        $allowed = [
            'content-type',
            'accept',
            'user-agent',
            'x-request-id',
            'x-correlation-id',
            'x-content-type-options',
        ];

        $out = [];
        foreach ($headers as $key => $value) {
            $lower = strtolower((string) $key);
            if (in_array($lower, $allowed, true)) {
                $out[$lower] = $value;
            } elseif (in_array($lower, ['authorization', 'cookie', 'set-cookie'], true)) {
                $out[$lower] = '[REDACTED]';
            }
        }

        return $out;
    }

    private function redactBody(array|string|null $body): ?string
    {
        if ($body === null) {
            return null;
        }

        if (is_array($body)) {
            $body = $this->redactArray($body);
            $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $this->truncate(is_string($encoded) ? $encoded : null);
        }

        $trimmed = trim($body);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            $encoded = json_encode(
                $this->redactArray($decoded),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );

            return $this->truncate(is_string($encoded) ? $encoded : $trimmed);
        }

        // Form bodies
        if (str_contains($trimmed, 'client_secret=') || str_contains($trimmed, 'access_token=')) {
            $trimmed = preg_replace(
                '/(client_secret|access_token|refresh_token|code)=([^&\s]+)/i',
                '$1=[REDACTED]',
                $trimmed,
            ) ?? $trimmed;
        }

        return $this->truncate($trimmed);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redactArray(array $data): array
    {
        $sensitive = [
            'access_token',
            'refresh_token',
            'client_secret',
            'password',
            'authorization',
            'code',
        ];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $sensitive, true)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redactArray($value);
            }
        }

        return $data;
    }

    private function truncate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (mb_strlen($value) <= self::MAX_BODY_CHARS) {
            return $value;
        }

        return mb_substr($value, 0, self::MAX_BODY_CHARS).'…[truncated]';
    }

    private function byteLength(array|string|null $body): ?int
    {
        if ($body === null) {
            return null;
        }

        if (is_array($body)) {
            $encoded = json_encode($body);

            return is_string($encoded) ? strlen($encoded) : null;
        }

        return strlen($body);
    }
}
