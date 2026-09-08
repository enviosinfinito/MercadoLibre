<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'name',
    'target_module',
    'columns',
    'formula_columns',
    'references_format',
    'row_granularity',
    'is_default',
    'is_active',
    'created_by',
    'updated_by',
])]
class ExportPreset extends Model
{
    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'formula_columns' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForModule(Builder $query, string $module): Builder
    {
        return $query->where('target_module', $module);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
