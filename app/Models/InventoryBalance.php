<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
        'workspace_id',
        'inventory_item_id',
        'quantity_on_hand',
        'quantity_reserved',
        'quantity_available',
])]
class InventoryBalance extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:6',
            'quantity_reserved' => 'decimal:6',
            'quantity_available' => 'decimal:6',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

}
