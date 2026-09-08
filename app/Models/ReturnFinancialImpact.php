<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'return_id',
    'sale_amount',
    'refund_amount',
    'shipping_cost',
    'return_shipping_cost',
    'product_cost',
    'fee_not_recovered',
    'other_costs',
    'estimated_total_loss',
    'currency_code',
    'breakdown',
])]
class ReturnFinancialImpact extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'sale_amount' => 'decimal:6',
            'refund_amount' => 'decimal:6',
            'shipping_cost' => 'decimal:6',
            'return_shipping_cost' => 'decimal:6',
            'product_cost' => 'decimal:6',
            'fee_not_recovered' => 'decimal:6',
            'other_costs' => 'decimal:6',
            'estimated_total_loss' => 'decimal:6',
            'breakdown' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function returnCase(): BelongsTo
    {
        return $this->belongsTo(ReturnCase::class, 'return_id');
    }
}
