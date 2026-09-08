<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Database\Factories\MarketplaceInboundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'connection_id',
    'variant_id',
    'from_warehouse_id',
    'qty_sent',
    'qty_confirmed',
    'external_inbound_id',
    'full_stock_operation_id',
    'status',
    'sent_at',
    'matched_at',
    'notes',
])]
class MarketplaceInbound extends Model
{
    /** @use HasFactory<MarketplaceInboundFactory> */
    use BelongsToWorkspace, HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_MATCHED = 'matched';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_UNMATCHED = 'unmatched';

    protected static function newFactory(): MarketplaceInboundFactory
    {
        return MarketplaceInboundFactory::new();
    }

    protected function casts(): array
    {
        return [
            'qty_sent' => 'decimal:6',
            'qty_confirmed' => 'decimal:6',
            'sent_at' => 'datetime',
            'matched_at' => 'datetime',
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

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function fullStockOperation(): BelongsTo
    {
        return $this->belongsTo(FullStockOperation::class);
    }
}
