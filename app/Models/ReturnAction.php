<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'product_id',
    'ml_item_id',
    'assigned_user_id',
    'status',
    'notes',
    'action_taken',
    'status_changed_at',
])]
class ReturnAction extends Model
{
    use BelongsToWorkspace;

    public const STATUSES = [
        'review',
        'investigating',
        'problem_identified',
        'action_taken',
        'resolved',
        'ignore',
    ];

    protected function casts(): array
    {
        return [
            'status_changed_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function notesHistory(): HasMany
    {
        return $this->hasMany(ReturnActionNote::class, 'return_action_id');
    }
}
