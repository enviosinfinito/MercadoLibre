<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'connection_id',
    'product_id',
    'variant_id',
    'ml_item_id',
    'ml_variation_id',
    'variant_label',
    'sku',
    'date',
    'units_sold',
    'returned_units',
    'return_count',
    'returned_amount',
    'return_rate',
])]
class ReturnVariantDailyStat extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'units_sold' => 'integer',
            'returned_units' => 'integer',
            'return_count' => 'integer',
            'returned_amount' => 'decimal:6',
            'return_rate' => 'decimal:4',
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
