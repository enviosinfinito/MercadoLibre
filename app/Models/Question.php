<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'connection_id',
    'external_question_id',
    'external_item_id',
    'buyer_external_id',
    'status',
    'question_text',
    'answer_text',
    'asked_at',
    'answered_at',
    'raw_snapshot_id',
    'meta',
])]
class Question extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'asked_at' => 'datetime',
            'answered_at' => 'datetime',
            'meta' => 'array',
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

    public function rawSnapshot(): BelongsTo
    {
        return $this->belongsTo(RawResourceSnapshot::class, 'raw_snapshot_id');
    }
}
