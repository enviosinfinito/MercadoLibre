<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'analytics_dashboard_id',
    'type',
    'title',
    'query',
    'viz_options',
    'grid',
    'sort_order',
])]
class AnalyticsWidget extends Model
{
    protected function casts(): array
    {
        return [
            'query' => 'array',
            'viz_options' => 'array',
            'grid' => 'array',
        ];
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(AnalyticsDashboard::class, 'analytics_dashboard_id');
    }
}
