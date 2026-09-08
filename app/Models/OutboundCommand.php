<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
        'workspace_id',
        'connection_id',
        'command_type',
        'status',
        'payload',
        'dry_run',
        'result',
        'attempts',
        'last_error_redacted',
        'idempotency_key',
        'available_at',
        'processed_at',
])]
class OutboundCommand extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'dry_run' => 'boolean',
            'available_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

}
