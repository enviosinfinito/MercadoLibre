<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'product_id',
    'ml_item_id',
    'variant_id',
    'alert_type',
    'level',
    'title',
    'body',
    'status',
    'context',
    'dedupe_key',
    'triggered_at',
    'acknowledged_at',
])]
class ReturnAlert extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'triggered_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
