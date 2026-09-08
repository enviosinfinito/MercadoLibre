<?php

namespace App\Domain\Cash\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Ephemeral progress for cash report sync (settlement / release / reconcile).
 * Survives across queue jobs; TTL keeps UI from showing stale "running" forever.
 */
final class CashReportSyncProgress
{
    private const TTL_SECONDS = 7200;

    /**
     * @return array<string, mixed>
     */
    public static function get(int $workspaceId, int $connectionId): array
    {
        $data = Cache::get(self::key($workspaceId, $connectionId));

        return is_array($data) ? $data : self::empty($workspaceId, $connectionId);
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public static function start(int $workspaceId, int $connectionId, array $kinds = ['settlement', 'release']): array
    {
        $reports = [];
        foreach ($kinds as $kind) {
            $reports[$kind] = self::reportState('queued', 'En cola…');
        }

        $state = [
            'workspace_id' => $workspaceId,
            'connection_id' => $connectionId,
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'active' => true,
            'reports' => $reports,
            'reconcile' => self::reportState('queued', 'Reconciliación en cola…'),
        ];

        Cache::put(self::key($workspaceId, $connectionId), $state, self::TTL_SECONDS);

        return $state;
    }

    public static function setReport(
        int $workspaceId,
        int $connectionId,
        string $kind,
        string $phase,
        string $message,
        array $extra = [],
    ): void {
        $state = self::get($workspaceId, $connectionId);
        if (($state['connection_id'] ?? null) !== $connectionId) {
            $state = self::empty($workspaceId, $connectionId);
        }

        $prev = is_array($state['reports'][$kind] ?? null) ? $state['reports'][$kind] : [];
        $state['reports'][$kind] = array_merge($prev, self::reportState($phase, $message), $extra, [
            'updated_at' => now()->toIso8601String(),
        ]);
        $state['updated_at'] = now()->toIso8601String();
        $state['active'] = self::computeActive($state);

        Cache::put(self::key($workspaceId, $connectionId), $state, self::TTL_SECONDS);
    }

    public static function setReconcile(
        int $workspaceId,
        int $connectionId,
        string $phase,
        string $message,
        array $extra = [],
    ): void {
        $state = self::get($workspaceId, $connectionId);
        $prev = is_array($state['reconcile'] ?? null) ? $state['reconcile'] : [];
        $state['reconcile'] = array_merge($prev, self::reportState($phase, $message), $extra, [
            'updated_at' => now()->toIso8601String(),
        ]);
        $state['updated_at'] = now()->toIso8601String();
        $state['active'] = self::computeActive($state);

        Cache::put(self::key($workspaceId, $connectionId), $state, self::TTL_SECONDS);
    }

    /**
     * @return array<string, mixed>
     */
    private static function empty(int $workspaceId, int $connectionId): array
    {
        return [
            'workspace_id' => $workspaceId,
            'connection_id' => $connectionId,
            'started_at' => null,
            'updated_at' => null,
            'active' => false,
            'reports' => [],
            'reconcile' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function reportState(string $phase, string $message): array
    {
        return [
            'phase' => $phase,
            'message' => $message,
            'attempt' => null,
            'max_attempts' => null,
            'file_name' => null,
            'rows' => null,
            'created' => null,
            'updated' => null,
            'report_shape' => null,
            'local_file_id' => null,
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function computeActive(array $state): bool
    {
        $terminal = ['done', 'failed', 'skipped'];
        foreach ($state['reports'] ?? [] as $report) {
            if (! is_array($report)) {
                continue;
            }
            $phase = (string) ($report['phase'] ?? '');
            if ($phase !== '' && ! in_array($phase, $terminal, true)) {
                return true;
            }
        }
        $reconcilePhase = (string) (($state['reconcile'] ?? [])['phase'] ?? '');
        if ($reconcilePhase !== '' && ! in_array($reconcilePhase, $terminal, true)) {
            return true;
        }

        return false;
    }

    private static function key(int $workspaceId, int $connectionId): string
    {
        return "cash.report_sync.{$workspaceId}.{$connectionId}";
    }
}
