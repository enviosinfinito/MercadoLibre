<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Database\Factories\SupplierPurchaseOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'supplier_name',
    'status',
    'ordered_at',
    'notes',
])]
class SupplierPurchaseOrder extends Model
{
    /** @use HasFactory<SupplierPurchaseOrderFactory> */
    use BelongsToWorkspace, HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ORDERED = 'ordered';

    public const STATUS_RECEIVING = 'receiving';

    public const STATUS_CLOSED = 'closed';

    protected static function newFactory(): SupplierPurchaseOrderFactory
    {
        return SupplierPurchaseOrderFactory::new();
    }

    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierPurchaseOrderLine::class);
    }

    public function refreshStatusFromLines(): void
    {
        $this->loadMissing('lines');
        if ($this->lines->isEmpty()) {
            return;
        }

        $anyReceived = $this->lines->contains(
            fn (SupplierPurchaseOrderLine $line) => bccomp((string) $line->qty_received, '0', 6) === 1,
        );
        $allCovered = $this->lines->every(
            fn (SupplierPurchaseOrderLine $line) => bccomp((string) $line->qty_received, (string) $line->qty_ordered, 6) >= 0,
        );

        if ($allCovered && $anyReceived) {
            $this->status = self::STATUS_CLOSED;
        } elseif ($anyReceived) {
            $this->status = self::STATUS_RECEIVING;
        } elseif ($this->status === self::STATUS_DRAFT) {
            $this->status = self::STATUS_ORDERED;
        }

        $this->save();
    }
}
