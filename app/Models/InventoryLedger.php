<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
        'workspace_id',
        'inventory_item_id',
        'variant_id',
        'warehouse_id',
        'movement_type',
        'quantity_delta',
        'quantity_after',
        'reference_type',
        'reference_id',
        'idempotency_key',
        'meta',
        'occurred_at',
])]
class InventoryLedger extends Model
{
    use BelongsToWorkspace;

    protected $table = 'inventory_ledger';

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'decimal:6',
            'quantity_after' => 'decimal:6',
            'meta' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

}
