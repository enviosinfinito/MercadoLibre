<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use App\Domain\Shared\Support\PublicAppUrl;
use Database\Factories\ConnectionInviteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'provider',
    'token',
    'created_by',
    'expires_at',
    'used_at',
    'revoked_at',
])]
class ConnectionInvite extends Model
{
    /** @use HasFactory<ConnectionInviteFactory> */
    use BelongsToWorkspace, HasFactory;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isUsable(): bool
    {
        if ($this->revoked_at !== null || $this->used_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * @return 'active'|'used'|'expired'|'revoked'
     */
    public function status(): string
    {
        if ($this->revoked_at !== null) {
            return 'revoked';
        }

        if ($this->used_at !== null) {
            return 'used';
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return 'expired';
        }

        return 'active';
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->whereNull('used_at')
            ->where(function (Builder $builder): void {
                $builder->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function url(): string
    {
        return PublicAppUrl::to('connect/'.$this->token);
    }
}
