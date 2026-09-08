<?php

namespace App\Services\Export\Support;

use App\Models\User;
use App\Services\Export\Contracts\ExportColumnRegistryInterface;
use App\Services\Export\Contracts\ExportRowProviderInterface;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

abstract class EloquentExportRowProvider implements ExportRowProviderInterface
{
    public function __construct(
        protected readonly ExportColumnRegistryInterface $registry,
    ) {}

    abstract public function module(): string;

    /**
     * @return class-string<Model>
     */
    abstract protected function modelClass(): string;

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    abstract protected function applyFilters(Builder $query, array $filters, User $user): Builder;

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected function scopeWorkspace(Builder $query, int $workspaceId): Builder
    {
        if ($this->modelClass()::query()->getModel()->isFillable('workspace_id')
            || $this->modelHasWorkspaceColumn()) {
            $query->where('workspace_id', $workspaceId);
        }

        return $query;
    }

    protected function modelHasWorkspaceColumn(): bool
    {
        $model = $this->modelClass()::query()->getModel();

        return in_array('workspace_id', $model->getFillable(), true)
            || array_key_exists('workspace_id', $model->getAttributes())
            || method_exists($model, 'workspace');
    }

    /**
     * @return Builder<Model>
     */
    protected function baseQuery(int $workspaceId, array $filters, User $user): Builder
    {
        $query = $this->modelClass()::query();
        $this->scopeWorkspace($query, $workspaceId);

        return $this->applyFilters($query, $filters, $user);
    }

    public function count(int $workspaceId, array $filters, User $user): int
    {
        return (int) $this->baseQuery($workspaceId, $filters, $user)->count();
    }

    public function resolveIds(
        int $workspaceId,
        array $filters,
        ?array $ids,
        string $selectionMode,
        User $user,
    ): array {
        if ($selectionMode === 'ids') {
            $ids = array_values(array_unique(array_map('intval', $ids ?? [])));
            if ($ids === []) {
                return [];
            }

            return $this->baseQuery($workspaceId, [], $user)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return $this->baseQuery($workspaceId, $filters, $user)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function iterateRows(
        int $workspaceId,
        array $ids,
        array $columnKeys,
        User $user,
    ): Generator {
        $batchSize = (int) config('export.batch_size', 200);
        $allowed = $this->registry->filterByUserPermissions($columnKeys, $user);

        foreach (array_chunk($ids, $batchSize) as $chunk) {
            $models = $this->modelClass()::query()
                ->whereIn('id', $chunk)
                ->orderBy('id')
                ->get()
                ->keyBy('id');

            foreach ($chunk as $id) {
                $model = $models->get($id);
                if (! $model) {
                    continue;
                }

                yield $this->mapModelToRow($model, $allowed);
            }
        }
    }

    /**
     * @param  list<string>  $columnKeys
     * @return array<string, mixed>
     */
    protected function mapModelToRow(Model $model, array $columnKeys): array
    {
        $row = [];
        foreach ($columnKeys as $key) {
            $label = $this->registry->getLabel($key) ?? $key;
            $row[$label] = $this->formatValue($model->getAttribute($key));
        }

        return $row;
    }

    protected function formatValue(mixed $value): mixed
    {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
        }

        return $value;
    }
}
