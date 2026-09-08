<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Database\Factories\CostLayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'variant_id',
    'warehouse_id',
    'qty_original',
    'qty_remaining',
    'unit_cost_amount',
    'unit_cost_currency',
    'fx_rate',
    'fx_from',
    'fx_to',
    'fx_source',
    'fx_dated_at',
    'unit_cost_reporting_amount',
    'reporting_currency',
    'source_type',
    'purchase_order_line_id',
    'qty_expected',
    'notes',
    'received_at',
])]
class CostLayer extends Model
{
    /** @use HasFactory<CostLayerFactory> */
    use BelongsToWorkspace, HasFactory;

    protected function casts(): array
    {
        return [
            'qty_original' => 'decimal:6',
            'qty_remaining' => 'decimal:6',
            'qty_expected' => 'decimal:6',
            'unit_cost_amount' => 'decimal:6',
            'fx_rate' => 'decimal:6',
            'unit_cost_reporting_amount' => 'decimal:6',
            'fx_dated_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(SupplierPurchaseOrderLine::class, 'purchase_order_line_id');
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(CostLayerConsumption::class);
    }
}
