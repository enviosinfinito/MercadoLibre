<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'owner_user_id',
    'name',
    'description',
    'visibility',
    'viz_type',
    'query',
    'viz_options',
])]
class AnalyticsReport extends Model
{
    use BelongsToWorkspace;

    public const VISIBILITY_PERSONAL = 'personal';

    public const VISIBILITY_WORKSPACE = 'workspace';

    protected function casts(): array
    {
        return [
            'query' => 'array',
            'viz_options' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
