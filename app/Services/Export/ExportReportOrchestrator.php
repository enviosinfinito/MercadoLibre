<?php

namespace App\Services\Export;

use App\Jobs\GenerateExportJob;
use App\Models\ExportRun;
use App\Models\User;
use Illuminate\Support\Str;

final class ExportReportOrchestrator
{
    public function __construct(
        private readonly ExportModuleCatalog $catalog,
        private readonly ExportColumnResolver $resolver,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<int>|null  $ids
     * @param  list<string>|null  $requestColumns
     * @param  array<string, mixed>|null  $query  analytics AST
     * @return array{token: string, run: ExportRun}
     */
    public function start(
        User $user,
        int $workspaceId,
        string $targetModule,
        string $selectionMode,
        array $filters = [],
        ?array $ids = null,
        ?int $exportConfigId = null,
        ?int $exportPresetId = null,
        ?array $requestColumns = null,
        ?string $referencesFormat = null,
        ?int $scheduledExportId = null,
        ?array $query = null,
        string $source = 'manual',
    ): array {
        $module = $this->catalog->get($targetModule);
        if ($module->requiresPlatformAdmin && ! $user->is_platform_admin) {
            abort(403, 'Export module requires platform admin.');
        }

        $resolved = $this->resolver->resolveForUser(
            $user,
            $workspaceId,
            $targetModule,
            $exportPresetId,
            $exportConfigId,
            $requestColumns,
            $referencesFormat,
        );

        $provider = $module->provider;
        $totalRows = $selectionMode === 'ids'
            ? count($ids ?? [])
            : ($targetModule === 'analytics_query'
                ? 0
                : $provider->count($workspaceId, $filters, $user));

        $token = (string) Str::uuid();
        $run = ExportRun::query()->create([
            'workspace_id' => $workspaceId,
            'user_id' => $user->id,
            'scheduled_export_id' => $scheduledExportId,
            'token' => $token,
            'report_type' => $targetModule,
            'target_module' => $targetModule,
            'format' => 'xlsx',
            'status' => 'queued',
            'progress' => 0,
            'selection_mode' => $selectionMode,
            'total_rows' => $totalRows,
            'estimated_time' => $this->estimateTime($totalRows),
            'filters' => $filters,
            'query' => $query,
            'export_params' => [
                'selection_mode' => $selectionMode,
                'ids' => $selectionMode === 'ids' ? array_values(array_map('intval', $ids ?? [])) : null,
                'export_config_id' => $exportConfigId,
                'export_preset_id' => $exportPresetId,
                'column_keys' => $resolved['column_keys'],
                'column_config' => $resolved['column_config'],
                'formula_columns' => $resolved['formula_columns'],
                'row_granularity' => $resolved['row_granularity'],
                'references_format' => $resolved['references_format'],
                'config_label' => $resolved['source'],
                'source' => $source,
                'resolver_source' => $resolved['source'],
            ],
            'last_activity_at' => now(),
        ]);

        try {
            GenerateExportJob::dispatch($workspaceId, $run->id)
                ->onQueue((string) config('export.queue', 'exports'));
        } catch (\Throwable $e) {
            $run->markError('Failed to dispatch export job: '.$e->getMessage());
            throw $e;
        }

        return ['token' => $token, 'run' => $run];
    }

    private function estimateTime(int $totalRows): string
    {
        if ($totalRows <= 0) {
            return '~1 min';
        }
        $minutes = max(1, (int) ceil($totalRows / 2000));

        return '~'.$minutes.' min';
    }
}
