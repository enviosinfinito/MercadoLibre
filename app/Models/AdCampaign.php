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
    'ad_advertiser_id',
    'external_campaign_id',
    'name',
    'status',
    'strategy',
    'meta',
])]
class AdCampaign extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function advertiser(): BelongsTo
    {
        return $this->belongsTo(AdAdvertiser::class, 'ad_advertiser_id');
    }

    public function spendDaily(): HasMany
    {
        return $this->hasMany(AdSpendDaily::class, 'ad_campaign_id');
    }
}
