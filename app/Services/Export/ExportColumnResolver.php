<?php

namespace App\Services\Export;

use App\Models\ExportPreset;
use App\Models\User;
use App\Models\UserExportPreference;

final class ExportColumnResolver
{
    public function __construct(
        private readonly ExportModuleCatalog $catalog,
    ) {}

    /**
     * @param  list<string>|null  $requestColumns
     * @return array{
     *   column_keys: list<string>,
     *   column_config: list<string>,
     *   references_format: string|null,
     *   row_granularity: string|null,
     *   formula_columns: list<array{key: string, name: string, expression: string}>,
     *   source: string
     * }
     */
    public function resolveForUser(
        User $user,
        int $workspaceId,
        string $targetModule,
        ?int $exportPresetId = null,
        ?int $exportConfigId = null,
        ?array $requestColumns = null,
        ?string $requestedReferencesFormat = null,
    ): array {
        $module = $this->catalog->get($targetModule);
        $registry = $module->registry;

        $referencesFormat = $requestedReferencesFormat;
        $rowGranularity = null;
        $formulaColumns = [];
        $columnKeys = null;
        $source = 'registry_default';

        if ($exportPresetId) {
            $preset = ExportPreset::query()
                ->active()
                ->forModule($targetModule)
                ->where(function ($q) use ($workspaceId) {
                    $q->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId);
                })
                ->find($exportPresetId);
            if ($preset) {
                $referencesFormat = $preset->references_format ?: $referencesFormat;
                $rowGranularity = $preset->row_granularity;
                $formulaColumns = $this->normalizeFormulaColumns($preset->formula_columns ?? []);
                $columnKeys = $preset->columns ?? [];
                $source = 'admin_preset';
            }
        }

        if ($columnKeys === null && $exportConfigId) {
            $config = UserExportPreference::query()
                ->where('workspace_id', $workspaceId)
                ->where('user_id', $user->id)
                ->where('target_module', $targetModule)
                ->where('id', $exportConfigId)
                ->first();
            if ($config) {
                $referencesFormat = $config->references_format ?: $referencesFormat;
                $rowGranularity = $config->row_granularity;
                $formulaColumns = $this->normalizeFormulaColumns($config->formula_columns ?? []);
                $columnKeys = $config->columns ?? [];
                $source = 'user_config';
            }
        }

        if ($columnKeys === null) {
            $defaultUserConfig = UserExportPreference::query()
                ->where('workspace_id', $workspaceId)
                ->where('user_id', $user->id)
                ->where('target_module', $targetModule)
                ->where('is_default', true)
                ->first();
            if ($defaultUserConfig) {
                $referencesFormat = $defaultUserConfig->references_format ?: $referencesFormat;
                $rowGranularity = $defaultUserConfig->row_granularity;
                $formulaColumns = $this->normalizeFormulaColumns($defaultUserConfig->formula_columns ?? []);
                $columnKeys = $defaultUserConfig->columns ?? [];
                $source = 'user_default_config';
            }
        }

        if ($columnKeys === null && ! empty($requestColumns)) {
            $columnKeys = $requestColumns;
            $source = 'request_columns';
        }

        if ($columnKeys === null) {
            $defaultPreset = ExportPreset::query()
                ->active()
                ->forModule($targetModule)
                ->where('is_default', true)
                ->where(function ($q) use ($workspaceId) {
                    $q->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId);
                })
                ->first();
            if ($defaultPreset) {
                $referencesFormat = $defaultPreset->references_format ?: $referencesFormat;
                $rowGranularity = $defaultPreset->row_granularity;
                $formulaColumns = $this->normalizeFormulaColumns($defaultPreset->formula_columns ?? []);
                $columnKeys = $defaultPreset->columns ?? [];
                $source = 'admin_default_preset';
            }
        }

        if ($columnKeys === null) {
            $columnKeys = $registry->getDefaultColumnsForUser($user);
            $source = 'registry_default';
        }

        $formulaMap = [];
        foreach ($formulaColumns as $formula) {
            $formulaMap[$formula['key']] = $formula['name'];
        }

        $orderedKeys = [];
        $columnConfig = [];
        foreach ($columnKeys as $key) {
            if (! is_string($key)) {
                continue;
            }
            if (str_starts_with($key, 'formula__')) {
                if (! isset($formulaMap[$key])) {
                    continue;
                }
                $orderedKeys[] = $key;
                $columnConfig[] = $formulaMap[$key];
                continue;
            }
            $allowed = $registry->filterByUserPermissions([$key], $user);
            if ($allowed === []) {
                continue;
            }
            $orderedKeys[] = $key;
            $columnConfig[] = $registry->getLabel($key) ?? $key;
        }

        return [
            'column_keys' => $orderedKeys,
            'column_config' => $columnConfig,
            'references_format' => $module->supportsReferencesFormat ? ($referencesFormat ?: 'comma') : null,
            'row_granularity' => $module->supportsRowGranularity ? ($rowGranularity ?: 'package') : null,
            'formula_columns' => $formulaColumns,
            'source' => $source,
        ];
    }

    /**
     * @param  mixed  $raw
     * @return list<array{key: string, name: string, expression: string}>
     */
    public function normalizeFormulaColumns(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $max = (int) config('export.formula_expression_max_length', 500);
        $out = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $key = (string) ($item['key'] ?? '');
            $name = trim((string) ($item['name'] ?? ''));
            $expression = trim((string) ($item['expression'] ?? ''));
            if ($key === '' || ! preg_match('/^formula__[a-z0-9_]+$/', $key)) {
                if ($name !== '') {
                    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name) ?? '');
                    $key = 'formula__'.trim($slug, '_');
                }
            }
            if ($key === '' || $name === '' || $expression === '' || strlen($expression) > $max) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'name' => $name,
                'expression' => $expression,
            ];
        }

        return $out;
    }
}
