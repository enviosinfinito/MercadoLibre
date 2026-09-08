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
    'ml_item_id',
    'sku',
    'date',
    'units_sold',
    'gross_sales',
    'returned_units',
    'return_count',
    'returned_amount',
    'return_rate',
    'estimated_loss',
    'dominant_reason_group',
    'risk_score',
    'confidence_score',
    'sparkline_14d',
])]
class ReturnProductDailyStat extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'units_sold' => 'integer',
            'gross_sales' => 'decimal:6',
            'returned_units' => 'integer',
            'return_count' => 'integer',
            'returned_amount' => 'decimal:6',
            'return_rate' => 'decimal:4',
            'estimated_loss' => 'decimal:6',
            'risk_score' => 'integer',
            'sparkline_14d' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
