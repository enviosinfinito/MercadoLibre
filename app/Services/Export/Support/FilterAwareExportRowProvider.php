<?php

namespace App\Services\Export\Support;

use App\Models\User;
use App\Services\Export\Contracts\ExportColumnRegistryInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class FilterAwareExportRowProvider extends EloquentExportRowProvider
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  Closure(Builder<Model>, array<string, mixed>, User): Builder<Model>|null  $filterApplier
     */
    public function __construct(
        ExportColumnRegistryInterface $registry,
        private readonly string $moduleKey,
        private readonly string $eloquentModel,
        private readonly bool $workspaceScoped = true,
        private readonly ?Closure $filterApplier = null,
    ) {
        parent::__construct($registry);
    }

    public function module(): string
    {
        return $this->moduleKey;
    }

    protected function modelClass(): string
    {
        return $this->eloquentModel;
    }

    protected function scopeWorkspace(Builder $query, int $workspaceId): Builder
    {
        if ($this->workspaceScoped) {
            $query->where($query->getModel()->getTable().'.workspace_id', $workspaceId);
        }

        return $query;
    }

    protected function applyFilters(Builder $query, array $filters, User $user): Builder
    {
        if ($this->filterApplier) {
            return ($this->filterApplier)($query, $filters, $user);
        }

        return $query;
    }
}
