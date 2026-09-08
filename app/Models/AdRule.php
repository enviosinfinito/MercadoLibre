<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'ad_rule_pack_id',
    'code',
    'name',
    'description',
    'lookback_days',
    'min_spend',
    'min_clicks',
    'condition_json',
    'action_json',
    'enabled',
    'auto_execute',
    'priority',
])]
class AdRule extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'lookback_days' => 'integer',
            'min_spend' => 'decimal:6',
            'min_clicks' => 'integer',
            'condition_json' => 'array',
            'action_json' => 'array',
            'enabled' => 'boolean',
            'auto_execute' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(AdRulePack::class, 'ad_rule_pack_id');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(AdActionProposal::class, 'ad_rule_id');
    }
}
