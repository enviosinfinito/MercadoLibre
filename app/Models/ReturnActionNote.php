<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'return_action_id',
    'user_id',
    'body',
    'event_type',
    'meta',
])]
class ReturnActionNote extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function returnAction(): BelongsTo
    {
        return $this->belongsTo(ReturnAction::class, 'return_action_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
