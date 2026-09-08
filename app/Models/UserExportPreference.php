<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'user_id',
    'target_module',
    'name',
    'columns',
    'formula_columns',
    'references_format',
    'row_granularity',
    'is_default',
])]
class UserExportPreference extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'formula_columns' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
