<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'connection_id',
    'resource_type',
    'cursor_value',
    'meta',
    'last_success_at',
])]
class SyncCursor extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'last_success_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }
}
