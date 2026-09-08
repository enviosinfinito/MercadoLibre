<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
        'workspace_id',
        'user_id',
        'name',
        'report_type',
        'filters',
        'columns',
        'is_shared',
        'analytics_report_id',
        'query',
])]
class SavedReportView extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'columns' => 'array',
            'query' => 'array',
            'is_shared' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

}
