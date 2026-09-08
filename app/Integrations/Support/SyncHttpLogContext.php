<?php

namespace App\Integrations\Support;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

final class SyncHttpLogContext
{
    private const KEY_WORKSPACE = 'sync_http.workspace_id';

    private const KEY_CONNECTION = 'sync_http.connection_id';

    private const KEY_SYNC_RUN = 'sync_http.sync_run_id';

    private const KEY_PROVIDER = 'sync_http.provider';

    private const KEY_CORRELATION = 'sync_http.correlation_id';

    public static function bind(
        int $workspaceId,
        ?int $connectionId = null,
        ?int $syncRunId = null,
        ?string $provider = null,
        ?string $correlationId = null,
    ): void {
        Context::add(self::KEY_WORKSPACE, $workspaceId);
        if ($connectionId !== null) {
            Context::add(self::KEY_CONNECTION, $connectionId);
        }
        if ($syncRunId !== null) {
            Context::add(self::KEY_SYNC_RUN, $syncRunId);
        }
        if ($provider !== null) {
            Context::add(self::KEY_PROVIDER, $provider);
        }
        Context::add(self::KEY_CORRELATION, $correlationId ?? (string) Str::uuid());
    }

    public static function setSyncRunId(int $syncRunId): void
    {
        Context::add(self::KEY_SYNC_RUN, $syncRunId);
    }

    public static function correlationId(): ?string
    {
        $value = Context::get(self::KEY_CORRELATION);

        return is_string($value) ? $value : null;
    }

    /**
     * @return array{
     *   workspace_id:?int,
     *   connection_id:?int,
     *   sync_run_id:?int,
     *   provider:?string,
     *   correlation_id:?string
     * }
     */
    public static function snapshot(): array
    {
        return [
            'workspace_id' => self::intOrNull(Context::get(self::KEY_WORKSPACE)),
            'connection_id' => self::intOrNull(Context::get(self::KEY_CONNECTION)),
            'sync_run_id' => self::intOrNull(Context::get(self::KEY_SYNC_RUN)),
            'provider' => self::stringOrNull(Context::get(self::KEY_PROVIDER)),
            'correlation_id' => self::stringOrNull(Context::get(self::KEY_CORRELATION)),
        ];
    }

    public static function clear(): void
    {
        Context::forget([
            self::KEY_WORKSPACE,
            self::KEY_CONNECTION,
            self::KEY_SYNC_RUN,
            self::KEY_PROVIDER,
            self::KEY_CORRELATION,
        ]);
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value))
            ? (int) $value
            : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
