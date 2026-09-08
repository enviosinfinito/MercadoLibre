<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'connection_id',
    'ad_action_proposal_id',
    'ad_rule_id',
    'acted_by_user_id',
    'actor',
    'status',
    'entity_type',
    'entity_id',
    'ml_item_id',
    'action_type',
    'before_payload',
    'after_payload',
    'undo_payload',
    'reason',
    'error_redacted',
    'wrote_to_ml',
    'undone_at',
])]
class AdActionExecution extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'before_payload' => 'array',
            'after_payload' => 'array',
            'undo_payload' => 'array',
            'wrote_to_ml' => 'boolean',
            'undone_at' => 'datetime',
        ];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(AdActionProposal::class, 'ad_action_proposal_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AdRule::class, 'ad_rule_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function actedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by_user_id');
    }
}
