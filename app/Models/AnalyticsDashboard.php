<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'owner_user_id',
    'name',
    'description',
    'visibility',
    'slug',
    'layout',
    'global_filters',
    'cloned_from_id',
    'is_home',
])]
class AnalyticsDashboard extends Model
{
    public const VISIBILITY_PLATFORM_TEMPLATE = 'platform_template';

    public const VISIBILITY_WORKSPACE = 'workspace';

    public const VISIBILITY_PERSONAL = 'personal';

    protected function casts(): array
    {
        return [
            'layout' => 'array',
            'global_filters' => 'array',
            'is_home' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function widgets(): HasMany
    {
        return $this->hasMany(AnalyticsWidget::class)->orderBy('sort_order');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(AnalyticsDashboardShare::class);
    }

    public function clonedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'cloned_from_id');
    }

    public function isPlatformTemplate(): bool
    {
        return $this->visibility === self::VISIBILITY_PLATFORM_TEMPLATE;
    }
}
