<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
        'workspace_id',
        'connection_id',
        'provider',
        'topic',
        'external_user_id',
        'external_resource_id',
        'dedupe_key',
        'payload',
        'headers_redacted',
        'status',
        'received_at',
        'processed_at',
])]
class RawWebhookEvent extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'headers_redacted' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

}
