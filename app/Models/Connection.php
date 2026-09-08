<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Database\Factories\ConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'workspace_id',
    'provider',
    'external_user_id',
    'site_id',
    'display_name',
    'permalink',
    'avatar_url',
    'reputation_level',
    'power_seller_status',
    'reputation_meta',
    'reputation_synced_at',
    'account_profile',
    'account_profile_synced_at',
    'color',
    'status',
    'token_generation',
    'freshness_status',
    'last_synced_at',
    'last_error_redacted',
    'needs_reauthorization',
])]
class Connection extends Model
{
    /** @use HasFactory<ConnectionFactory> */
    use BelongsToWorkspace, HasFactory;

    protected function casts(): array
    {
        return [
            'token_generation' => 'integer',
            'last_synced_at' => 'datetime',
            'reputation_meta' => 'array',
            'reputation_synced_at' => 'datetime',
            'account_profile' => 'array',
            'account_profile_synced_at' => 'datetime',
            'needs_reauthorization' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function credential(): HasOne
    {
        return $this->hasOne(EncryptedCredential::class);
    }

    public function capabilities(): HasMany
    {
        return $this->hasMany(ConnectionCapability::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

    public function syncProfiles(): HasMany
    {
        return $this->hasMany(ConnectionSyncProfile::class);
    }

    public function syncCursors(): HasMany
    {
        return $this->hasMany(SyncCursor::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function reputationSnapshots(): HasMany
    {
        return $this->hasMany(ConnectionReputationSnapshot::class);
    }
}
