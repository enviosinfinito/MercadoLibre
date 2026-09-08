<?php

namespace App\Http\Controllers\Export;

use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateExportJob;
use App\Models\ExportPreset;
use App\Models\ExportRun;
use App\Models\ScheduledExport;
use App\Models\UserExportPreference;
use App\Services\Export\Delivery\ExportDeliveryManager;
use App\Services\Export\ExportColumnResolver;
use App\Services\Export\ExportModuleCatalog;
use App\Services\Export\ExportPreviewService;
use App\Services\Export\ExportRegenerationService;
use App\Services\Export\ExportReportOrchestrator;
use App\Services\Export\ScheduledReportRunnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportPlatformController extends Controller
{
    public function hub(Request $request, ExportModuleCatalog $catalog, ExportDeliveryManager $delivery): Response
    {
        $workspaceId = (int) TenantContext::id();
        $user = $request->user();
        $module = (string) $request->query('module', 'orders');
        if (! in_array($module, $catalog->keys(), true)) {
            $module = 'orders';
        }

        $configs = UserExportPreference::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $user->id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $presets = ExportPreset::query()
            ->active()
            ->where(function ($q) use ($workspaceId) {
                $q->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId);
            })
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $schedules = ScheduledExport::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('id')
            ->get();

        $history = ExportRun::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate((int) config('export.history_page_size', 20));

        return Inertia::render('Exports/Hub', [
            'modules' => collect($catalog->all())->map(fn ($d) => [
                'key' => $d->key,
                'label' => $d->label,
                'supports_references_format' => $d->supportsReferencesFormat,
                'supports_row_granularity' => $d->supportsRowGranularity,
                'requires_platform_admin' => $d->requiresPlatformAdmin,
            ])->values(),
            'activeModule' => $module,
            'configs' => $configs,
            'presets' => $presets,
            'schedules' => $schedules,
            'history' => $history,
            'enabledDeliveryChannels' => $delivery->enabledTypes(),
            'availableColumns' => $catalog->registry($module)->getAvailableColumnsForUser($user),
        ]);
    }

    public function columns(Request $request, ExportModuleCatalog $catalog): JsonResponse
    {
        $data = $request->validate([
            'target_module' => ['required', 'string', Rule::in($catalog->keys())],
        ]);
        $module = $catalog->get($data['target_module']);
        if ($module->requiresPlatformAdmin && ! $request->user()->is_platform_admin) {
            abort(403);
        }

        return response()->json([
            'columns' => $module->registry->getAvailableColumnsForUser($request->user()),
            'defaults' => $module->registry->getDefaultColumnsForUser($request->user()),
            'supports_references_format' => $module->supportsReferencesFormat,
            'supports_row_granularity' => $module->supportsRowGranularity,
        ]);
    }

    public function start(Request $request, ExportReportOrchestrator $orchestrator, ExportModuleCatalog $catalog): JsonResponse
    {
        $data = $request->validate([
            'target_module' => ['required', 'string', Rule::in($catalog->keys())],
            'selection_mode' => ['required', 'string', Rule::in(['ids', 'filter'])],
            'ids' => ['nullable', 'array', 'max:'.(int) config('export.max_ids_per_request', 5000)],
            'ids.*' => ['integer'],
            'filters' => ['nullable', 'array'],
            'query' => ['nullable', 'array'],
            'export_config_id' => ['nullable', 'integer'],
            'export_preset_id' => ['nullable', 'integer'],
            'columns' => ['nullable', 'array'],
            'columns.*' => ['string'],
            'references_format' => ['nullable', 'string', Rule::in(['comma', 'columns'])],
        ]);

        $result = $orchestrator->start(
            user: $request->user(),
            workspaceId: (int) TenantContext::id(),
            targetModule: $data['target_module'],
            selectionMode: $data['selection_mode'],
            filters: $data['filters'] ?? [],
            ids: $data['ids'] ?? null,
            exportConfigId: $data['export_config_id'] ?? null,
            exportPresetId: $data['export_preset_id'] ?? null,
            requestColumns: $data['columns'] ?? null,
            referencesFormat: $data['references_format'] ?? null,
            query: $data['query'] ?? null,
        );

        return response()->json([
            'token' => $result['token'],
            'total_rows' => $result['run']->total_rows,
            'estimated_time' => $result['run']->estimated_time,
        ], 202);
    }

    public function status(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string']]);
        $run = $this->findRunByToken($data['token'], $request);

        $status = $run->status;
        if ($run->isStalled()) {
            $status = 'stalled';
        }

        return response()->json([
            'token' => $run->token,
            'status' => $status,
            'progress' => $run->progress,
            'total_rows' => $run->total_rows,
            'row_count' => $run->row_count,
            'estimated_time' => $run->estimated_time,
            'error' => $run->error_redacted,
            'download_url' => $run->status === 'completed' && $run->token
                ? route('exports.download', ['token' => $run->token])
                : null,
            'target_module' => $run->target_module,
            'export_params' => $run->export_params,
        ]);
    }

    public function preview(Request $request, ExportPreviewService $preview): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'mode' => ['nullable', 'string', Rule::in(['page', 'all'])],
            'offset' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1'],
        ]);
        $run = $this->findRunByToken($data['token'], $request);

        return response()->json($preview->preview(
            $run,
            $data['mode'] ?? 'page',
            (int) ($data['offset'] ?? 0),
            isset($data['limit']) ? (int) $data['limit'] : null,
        ));
    }

    public function download(string $token, Request $request): StreamedResponse
    {
        $run = $this->findRunByToken($token, $request);
        abort_unless($run->status === 'completed' && $run->storage_path, 404);
        $disk = $run->storage_disk ?: (string) config('export.disk', 'local');
        abort_unless(Storage::disk($disk)->exists($run->storage_path), 404);

        return Storage::disk($disk)->download($run->storage_path, basename($run->storage_path));
    }

    public function cancel(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string']]);
        $run = $this->findRunByToken($data['token'], $request);
        if (! in_array($run->status, ['completed', 'cancelled'], true)) {
            $run->markCancelled();
        }

        return response()->json(['ok' => true, 'status' => $run->status]);
    }

    public function resume(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string']]);
        $run = $this->findRunByToken($data['token'], $request);
        abort_unless(in_array($run->status, ['error', 'stalled', 'processing', 'queued'], true) || $run->isStalled(), 422);

        $run->forceFill([
            'status' => 'queued',
            'progress' => 0,
            'error_redacted' => null,
            'last_activity_at' => now(),
            'finished_at' => null,
        ])->save();

        GenerateExportJob::dispatch((int) $run->workspace_id, $run->id)
            ->onQueue((string) config('export.queue', 'exports'));

        return response()->json(['ok' => true, 'token' => $run->token]);
    }

    public function regenerate(Request $request, ExportRegenerationService $regeneration): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string']]);
        $run = $this->findRunByToken($data['token'], $request);
        $result = $regeneration->regenerate($run, $request->user());

        return response()->json(['token' => $result['token']], 202);
    }

    public function history(Request $request): JsonResponse
    {
        $pageSize = (int) config('export.history_page_size', 20);
        $runs = ExportRun::query()
            ->where('workspace_id', TenantContext::id())
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->paginate($pageSize);

        return response()->json($runs);
    }

    public function active(Request $request): JsonResponse
    {
        $runs = ExportRun::query()
            ->where('workspace_id', TenantContext::id())
            ->where('user_id', $request->user()->id)
            ->active()
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $runs]);
    }

    public function storeConfig(Request $request, ExportModuleCatalog $catalog, ExportColumnResolver $resolver): JsonResponse
    {
        $data = $this->validateConfigPayload($request, $catalog);
        $data['formula_columns'] = $resolver->normalizeFormulaColumns($data['formula_columns'] ?? []);
        $data['workspace_id'] = TenantContext::id();
        $data['user_id'] = $request->user()->id;

        if (! empty($data['is_default'])) {
            UserExportPreference::query()
                ->where('workspace_id', $data['workspace_id'])
                ->where('user_id', $data['user_id'])
                ->where('target_module', $data['target_module'])
                ->update(['is_default' => false]);
        }

        $config = UserExportPreference::query()->create($data);

        return response()->json(['config' => $config], 201);
    }

    public function updateConfig(Request $request, UserExportPreference $config, ExportModuleCatalog $catalog, ExportColumnResolver $resolver): JsonResponse
    {
        abort_unless((int) $config->workspace_id === (int) TenantContext::id(), 404);
        abort_unless((int) $config->user_id === (int) $request->user()->id, 403);

        $data = $this->validateConfigPayload($request, $catalog, false);
        if (array_key_exists('formula_columns', $data)) {
            $data['formula_columns'] = $resolver->normalizeFormulaColumns($data['formula_columns']);
        }

        if (! empty($data['is_default'])) {
            UserExportPreference::query()
                ->where('workspace_id', $config->workspace_id)
                ->where('user_id', $config->user_id)
                ->where('target_module', $data['target_module'] ?? $config->target_module)
                ->where('id', '!=', $config->id)
                ->update(['is_default' => false]);
        }

        $config->fill($data)->save();

        return response()->json(['config' => $config]);
    }

    public function destroyConfig(Request $request, UserExportPreference $config): JsonResponse
    {
        abort_unless((int) $config->workspace_id === (int) TenantContext::id(), 404);
        abort_unless((int) $config->user_id === (int) $request->user()->id, 403);
        $config->delete();

        return response()->json(['ok' => true]);
    }

    public function storePreset(Request $request, ExportModuleCatalog $catalog, ExportColumnResolver $resolver): JsonResponse
    {
        abort_unless($request->user()->is_platform_admin, 403);
        $data = $this->validateConfigPayload($request, $catalog);
        $data['formula_columns'] = $resolver->normalizeFormulaColumns($data['formula_columns'] ?? []);
        $data['workspace_id'] = TenantContext::id();
        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;
        $data['is_active'] = $data['is_active'] ?? true;

        $preset = ExportPreset::query()->create($data);

        return response()->json(['preset' => $preset], 201);
    }

    public function updatePreset(Request $request, ExportPreset $preset, ExportModuleCatalog $catalog, ExportColumnResolver $resolver): JsonResponse
    {
        abort_unless($request->user()->is_platform_admin, 403);
        abort_unless($preset->workspace_id === null || (int) $preset->workspace_id === (int) TenantContext::id(), 404);

        $data = $this->validateConfigPayload($request, $catalog, false);
        if (array_key_exists('formula_columns', $data)) {
            $data['formula_columns'] = $resolver->normalizeFormulaColumns($data['formula_columns']);
        }
        $data['updated_by'] = $request->user()->id;
        $preset->fill($data)->save();

        return response()->json(['preset' => $preset]);
    }

    public function destroyPreset(Request $request, ExportPreset $preset): JsonResponse
    {
        abort_unless($request->user()->is_platform_admin, 403);
        abort_unless($preset->workspace_id === null || (int) $preset->workspace_id === (int) TenantContext::id(), 404);
        $preset->delete();

        return response()->json(['ok' => true]);
    }

    public function storeSchedule(Request $request, ExportModuleCatalog $catalog): JsonResponse
    {
        $data = $this->validateSchedulePayload($request, $catalog);
        $schedule = new ScheduledExport($data);
        $schedule->workspace_id = TenantContext::id();
        $schedule->created_by = $request->user()->id;
        $schedule->format = 'xlsx';
        $schedule->report_type = $data['target_module'];
        $schedule->cron_expression = $schedule->cron_expression ?: '0 8 * * *';
        $schedule->next_run_at = $schedule->computeNextRunAt();
        $schedule->save();

        return response()->json(['schedule' => $schedule], 201);
    }

    public function updateSchedule(Request $request, ScheduledExport $scheduledExport, ExportModuleCatalog $catalog): JsonResponse
    {
        abort_unless((int) $scheduledExport->workspace_id === (int) TenantContext::id(), 404);
        $data = $this->validateSchedulePayload($request, $catalog, false);
        $scheduledExport->fill($data);
        if (isset($data['target_module'])) {
            $scheduledExport->report_type = $data['target_module'];
        }
        if (isset($data['schedule_type']) || isset($data['schedule_config'])) {
            $scheduledExport->next_run_at = $scheduledExport->computeNextRunAt();
        }
        $scheduledExport->save();

        return response()->json(['schedule' => $scheduledExport]);
    }

    public function destroySchedule(Request $request, ScheduledExport $scheduledExport): JsonResponse
    {
        abort_unless((int) $scheduledExport->workspace_id === (int) TenantContext::id(), 404);
        $scheduledExport->delete();

        return response()->json(['ok' => true]);
    }

    public function runScheduleNow(Request $request, ScheduledExport $scheduledExport, ScheduledReportRunnerService $runner): JsonResponse
    {
        abort_unless((int) $scheduledExport->workspace_id === (int) TenantContext::id(), 404);
        $runner->runOne($scheduledExport);

        return response()->json(['ok' => true]);
    }

    public function optionsForModule(Request $request, ExportModuleCatalog $catalog): JsonResponse
    {
        $data = $request->validate([
            'target_module' => ['required', 'string', Rule::in($catalog->keys())],
        ]);
        $workspaceId = (int) TenantContext::id();
        $user = $request->user();

        return response()->json([
            'configs' => UserExportPreference::query()
                ->where('workspace_id', $workspaceId)
                ->where('user_id', $user->id)
                ->where('target_module', $data['target_module'])
                ->orderByDesc('is_default')
                ->get(['id', 'name', 'is_default', 'columns', 'formula_columns']),
            'presets' => ExportPreset::query()
                ->active()
                ->forModule($data['target_module'])
                ->where(function ($q) use ($workspaceId) {
                    $q->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId);
                })
                ->orderByDesc('is_default')
                ->get(['id', 'name', 'is_default', 'columns', 'formula_columns']),
        ]);
    }

    private function findRunByToken(string $token, Request $request): ExportRun
    {
        $run = ExportRun::query()
            ->where('token', $token)
            ->where('workspace_id', TenantContext::id())
            ->firstOrFail();

        abort_unless((int) $run->user_id === (int) $request->user()->id || $request->user()->is_platform_admin, 403);

        return $run;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateConfigPayload(Request $request, ExportModuleCatalog $catalog, bool $creating = true): array
    {
        return $request->validate([
            'target_module' => [$creating ? 'required' : 'sometimes', 'string', Rule::in($catalog->keys())],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'columns' => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'columns.*' => ['string'],
            'formula_columns' => ['nullable', 'array'],
            'references_format' => ['nullable', 'string', Rule::in(['comma', 'columns'])],
            'row_granularity' => ['nullable', 'string', Rule::in(['package', 'shipment'])],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSchedulePayload(Request $request, ExportModuleCatalog $catalog, bool $creating = true): array
    {
        $enabled = config('export.enabled_delivery_channels', ['email']);

        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'target_module' => [$creating ? 'required' : 'sometimes', 'string', Rule::in($catalog->keys())],
            'filters' => ['nullable', 'array'],
            'schedule_type' => [$creating ? 'required' : 'sometimes', 'string', Rule::in(['daily', 'weekly', 'monthly'])],
            'schedule_config' => [$creating ? 'required' : 'sometimes', 'array'],
            'delivery_channels' => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'delivery_channels.*.type' => ['required', 'string', Rule::in($enabled)],
            'delivery_channels.*.value' => ['required', 'string', 'max:255'],
            'user_export_preference_id' => ['nullable', 'integer', 'exists:user_export_preferences,id'],
            'export_preset_id' => ['nullable', 'integer', 'exists:export_presets,id'],
            'is_active' => ['nullable', 'boolean'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);
    }
}
