<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
        'workspace_id',
        'cost_layer_id',
        'order_line_id',
        'quantity',
        'unit_cost_reporting_amount',
        'currency_code',
        'total_cost_reporting_amount',
])]
class CostLayerConsumption extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'unit_cost_reporting_amount' => 'decimal:6',
            'total_cost_reporting_amount' => 'decimal:6',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

}
