<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
        'workspace_id',
        'rule_id',
        'severity',
        'title',
        'body',
        'status',
        'context',
        'triggered_at',
        'acknowledged_at',
])]
class Alert extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'triggered_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

}
