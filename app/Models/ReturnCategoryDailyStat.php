<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'connection_id',
    'category_id',
    'category_name',
    'date',
    'units_sold',
    'returned_units',
    'return_count',
    'returned_amount',
    'return_rate',
    'products_affected',
])]
class ReturnCategoryDailyStat extends Model
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
            'products_affected' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
