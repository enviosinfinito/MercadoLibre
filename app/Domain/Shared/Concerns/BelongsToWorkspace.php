<?php

namespace App\Domain\Shared\Concerns;

use App\Domain\Shared\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin Model
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope('workspace', function (Builder $builder): void {
            $workspaceId = TenantContext::workspaceId();

            if ($workspaceId !== null) {
                $builder->where(
                    $builder->getModel()->getTable().'.workspace_id',
                    $workspaceId
                );
            }
        });

        static::creating(function (Model $model): void {
            if ($model->getAttribute('workspace_id') === null && TenantContext::has()) {
                $model->setAttribute('workspace_id', TenantContext::id());
            }
        });
    }

    public function initializeBelongsToWorkspace(): void
    {
        if (! in_array('workspace_id', $this->fillable, true)) {
            $this->fillable[] = 'workspace_id';
        }
    }
}
