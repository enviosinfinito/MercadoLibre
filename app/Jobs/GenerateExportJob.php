<?php

namespace App\Jobs;

use App\Domain\Shared\Support\TenantContext;
use App\Models\ExportRun;
use App\Models\User;
use App\Services\Export\ExportModuleCatalog;
use App\Services\Export\Modules\AnalyticsQueryExportRowProvider;
use App\Services\Export\XlsxExportWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class GenerateExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 15, 30];

    public int $timeout = 1200;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $exportRunId,
    ) {
        $this->onQueue((string) config('export.queue', 'exports'));
    }

    public function handle(ExportModuleCatalog $catalog, XlsxExportWriter $writer): void
    {
        TenantContext::set($this->workspaceId);

        try {
            $run = ExportRun::query()->withoutGlobalScopes()->findOrFail($this->exportRunId);
            if (in_array($run->status, ['cancelled', 'completed'], true)) {
                return;
            }

            $run->markProcessing(1);
            $started = hrtime(true);

            $user = User::query()->find($run->user_id);
            if (! $user) {
                $run->markError('Export user not found.');

                return;
            }

            $moduleKey = (string) ($run->target_module ?: $run->report_type);
            $module = $catalog->get($moduleKey);
            $params = $run->export_params ?? [];
            $selectionMode = (string) ($params['selection_mode'] ?? $run->selection_mode ?? 'filter');
            $columnKeys = $params['column_keys'] ?? [];
            $formulaColumns = $params['formula_columns'] ?? [];
            $filters = $run->filters ?? [];

            $registry = $module->registry;
            $keyToLabel = [];
            foreach ($registry->columns() as $key => $def) {
                $keyToLabel[$key] = $def['label'];
            }

            $tmpPath = storage_path('app/tmp/exports/'.$run->token.'.xlsx');
            if ($moduleKey === 'analytics_query') {
                /** @var AnalyticsQueryExportRowProvider $provider */
                $provider = $module->provider;
                $query = $run->query ?? $filters;
                $rows = $provider->iterateAnalyticsRows($this->workspaceId, is_array($query) ? $query : []);
                // Analytics uses dynamic headers from first row path — rebuild ordered keys from query result
                $orderedKeys = [];
                $dynamicRows = (function () use ($rows, &$orderedKeys) {
                    foreach ($rows as $i => $row) {
                        if ($i === 0) {
                            $orderedKeys = array_keys($row);
                        }
                        yield $row;
                    }
                })();
                // Write with label=key identity map for analytics
                $identity = [];
                // Materialize once to know headers
                $materialized = iterator_to_array($provider->iterateAnalyticsRows($this->workspaceId, is_array($query) ? $query : []), false);
                if ($materialized !== []) {
                    $headers = array_keys($materialized[0]);
                    $orderedKeys = $headers;
                    foreach ($headers as $h) {
                        $identity[$h] = $h;
                    }
                    $gen = (function () use ($materialized) {
                        foreach ($materialized as $row) {
                            yield $row;
                        }
                    })();
                    $result = $writer->write($tmpPath, $orderedKeys, $identity, [], $gen);
                } else {
                    $result = $writer->write($tmpPath, ['note'], ['note' => 'Note'], [], (function () {
                        yield ['Note' => 'Empty result'];
                    })());
                }
                unset($dynamicRows);
            } else {
                $provider = $module->provider;
                $ids = $provider->resolveIds(
                    $this->workspaceId,
                    $filters,
                    $params['ids'] ?? null,
                    $selectionMode,
                    $user,
                );
                $run->forceFill([
                    'total_rows' => count($ids),
                    'last_activity_at' => now(),
                ])->save();

                if ($this->isCancelled($run)) {
                    return;
                }

                $baseKeys = array_values(array_filter(
                    $columnKeys,
                    fn ($k) => is_string($k) && ! str_starts_with($k, 'formula__'),
                ));

                $total = max(1, count($ids));
                $processed = 0;
                $rowGenerator = (function () use ($provider, $ids, $baseKeys, $user, $run, $total, &$processed) {
                    foreach ($provider->iterateRows($this->workspaceId, $ids, $baseKeys, $user) as $row) {
                        if ($this->isCancelled($run)) {
                            return;
                        }
                        $processed++;
                        if ($processed % 50 === 0) {
                            $run->markProgress((int) floor(($processed / $total) * 90) + 5);
                        }
                        yield $row;
                    }
                })();

                $result = $writer->write(
                    $tmpPath,
                    array_values(array_filter($columnKeys, 'is_string')),
                    $keyToLabel,
                    $formulaColumns,
                    $rowGenerator,
                );
            }

            if ($this->isCancelled($run)) {
                @unlink($tmpPath);

                return;
            }

            $disk = (string) config('export.disk', config('filesystems.default', 'local'));
            $storagePath = sprintf(
                'exports/%d/%s_%s.xlsx',
                $this->workspaceId,
                $moduleKey,
                $run->token,
            );
            Storage::disk($disk)->put($storagePath, file_get_contents($tmpPath) ?: '');
            @unlink($tmpPath);

            $run->duration_ms = (int) ((hrtime(true) - $started) / 1_000_000);
            $run->markCompleted($disk, $storagePath, $result['row_count'], $result['file_size']);

            if ($run->scheduled_export_id) {
                SendScheduledExportDeliveryJob::dispatch($run->id)
                    ->onQueue((string) config('export.queue', 'exports'));
            }
        } catch (Throwable $e) {
            $run = ExportRun::query()->withoutGlobalScopes()->find($this->exportRunId);
            if ($run && ! in_array($run->status, ['cancelled', 'completed'], true)) {
                if (str_contains(strtolower($e->getMessage()), 'cancel')) {
                    $run->markCancelled();
                } else {
                    $run->markError($e->getMessage());
                }
            }
            throw $e;
        } finally {
            TenantContext::clear();
        }
    }

    public function failed(?Throwable $e): void
    {
        $run = ExportRun::query()->withoutGlobalScopes()->find($this->exportRunId);
        if ($run && ! in_array($run->status, ['completed', 'cancelled'], true)) {
            $run->markError($e?->getMessage() ?? 'Export failed');
        }
    }

    private function isCancelled(ExportRun $run): bool
    {
        $fresh = ExportRun::query()->withoutGlobalScopes()->find($run->id);
        if ($fresh && $fresh->status === 'cancelled') {
            return true;
        }

        return false;
    }
}
