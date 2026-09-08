<?php

namespace App\Services\Export;

use App\Models\ExportRun;
use App\Models\User;

final class ExportRegenerationService
{
    public function __construct(
        private readonly ExportReportOrchestrator $orchestrator,
    ) {}

    /**
     * @return array{token: string, run: ExportRun}
     */
    public function regenerate(ExportRun $original, User $user): array
    {
        $params = $original->export_params ?? [];
        $module = $original->target_module ?: $original->report_type;

        return $this->orchestrator->start(
            user: $user,
            workspaceId: (int) $original->workspace_id,
            targetModule: (string) $module,
            selectionMode: (string) ($params['selection_mode'] ?? 'filter'),
            filters: $original->filters ?? [],
            ids: $params['ids'] ?? null,
            exportConfigId: isset($params['export_config_id']) ? (int) $params['export_config_id'] : null,
            exportPresetId: isset($params['export_preset_id']) ? (int) $params['export_preset_id'] : null,
            requestColumns: $params['column_keys'] ?? null,
            referencesFormat: $params['references_format'] ?? null,
            query: $original->query,
            source: 'regenerate',
        );
    }
}
