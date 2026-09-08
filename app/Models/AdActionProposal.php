<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'connection_id',
    'ad_rule_id',
    'status',
    'entity_type',
    'entity_id',
    'ml_item_id',
    'action_type',
    'action_payload',
    'title',
    'reason',
    'priority',
    'estimated_impact_amount',
    'metrics_snapshot',
    'expires_at',
])]
class AdActionProposal extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'action_payload' => 'array',
            'metrics_snapshot' => 'array',
            'estimated_impact_amount' => 'decimal:6',
            'expires_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AdRule::class, 'ad_rule_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(AdActionExecution::class, 'ad_action_proposal_id');
    }
}
