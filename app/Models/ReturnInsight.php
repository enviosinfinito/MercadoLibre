<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'insight_type',
    'severity',
    'title',
    'body',
    'context',
    'period_key',
    'generated_at',
])]
class ReturnInsight extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
