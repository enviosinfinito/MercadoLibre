<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Database\Factories\SupplierPurchaseOrderLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'supplier_purchase_order_id',
    'variant_id',
    'qty_ordered',
    'qty_received',
    'unit_cost_amount',
    'currency',
])]
class SupplierPurchaseOrderLine extends Model
{
    /** @use HasFactory<SupplierPurchaseOrderLineFactory> */
    use BelongsToWorkspace, HasFactory;

    protected static function newFactory(): SupplierPurchaseOrderLineFactory
    {
        return SupplierPurchaseOrderLineFactory::new();
    }

    protected function casts(): array
    {
        return [
            'qty_ordered' => 'decimal:6',
            'qty_received' => 'decimal:6',
            'unit_cost_amount' => 'decimal:6',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(SupplierPurchaseOrder::class, 'supplier_purchase_order_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function costLayers(): HasMany
    {
        return $this->hasMany(CostLayer::class, 'purchase_order_line_id');
    }

    public function varianceQty(): string
    {
        return bcsub((string) $this->qty_received, (string) $this->qty_ordered, 6);
    }
}
