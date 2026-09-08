<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'analytics_dashboard_id',
    'user_id',
    'role_name',
    'permission',
])]
class AnalyticsDashboardShare extends Model
{
    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(AnalyticsDashboard::class, 'analytics_dashboard_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
