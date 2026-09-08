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
    'order_id',
    'external_claim_id',
    'type',
    'stage',
    'status',
    'reason',
    'reason_id',
    'reason_detail',
    'problem',
    'status_title',
    'status_description',
    'due_at',
    'affects_reputation',
    'has_incentive',
    'reputation_due_at',
    'resource',
    'resource_external_id',
    'notes',
    'opened_at',
    'closed_at',
    'raw_snapshot_id',
    'meta',
])]
class Claim extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'due_at' => 'datetime',
            'has_incentive' => 'boolean',
            'reputation_due_at' => 'datetime',
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function rawSnapshot(): BelongsTo
    {
        return $this->belongsTo(RawResourceSnapshot::class, 'raw_snapshot_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ClaimMessage::class);
    }

    public function canMessageMediator(): bool
    {
        $players = is_array($this->meta['players'] ?? null) ? $this->meta['players'] : [];

        foreach ($players as $player) {
            if (! is_array($player)) {
                continue;
            }
            if (($player['role'] ?? null) !== 'respondent') {
                continue;
            }
            $actions = is_array($player['available_actions'] ?? null) ? $player['available_actions'] : [];
            foreach ($actions as $action) {
                if (is_array($action) && ($action['action'] ?? null) === 'send_message_to_mediator') {
                    return true;
                }
                if (is_string($action) && $action === 'send_message_to_mediator') {
                    return true;
                }
            }
        }

        return false;
    }

    public function resolutionReason(): ?string
    {
        $resolution = is_array($this->meta['resolution'] ?? null) ? $this->meta['resolution'] : null;
        $reason = $resolution['reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }
}
