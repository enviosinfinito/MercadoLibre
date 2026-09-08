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
    'external_advertiser_id',
    'site_id',
    'name',
    'account_name',
    'meta',
])]
class AdAdvertiser extends Model
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

    public function campaigns(): HasMany
    {
        return $this->hasMany(AdCampaign::class);
    }

    public function spendDaily(): HasMany
    {
        return $this->hasMany(AdSpendDaily::class);
    }
}
