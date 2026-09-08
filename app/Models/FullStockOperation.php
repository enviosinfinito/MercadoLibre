<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Database\Factories\FullStockOperationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'connection_id',
    'external_operation_id',
    'seller_id',
    'inventory_id',
    'seller_product_id',
    'operation_type',
    'occurred_at',
    'available_quantity_delta',
    'not_available_quantity_delta',
    'result_total',
    'result_available',
    'result_not_available',
    'not_available_detail',
    'external_references',
    'raw',
    'channel_listing_variant_id',
    'variant_id',
    'marketplace_inbound_id',
])]
class FullStockOperation extends Model
{
    /** @use HasFactory<FullStockOperationFactory> */
    use BelongsToWorkspace, HasFactory;

    protected static function newFactory(): FullStockOperationFactory
    {
        return FullStockOperationFactory::new();
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'available_quantity_delta' => 'decimal:6',
            'not_available_quantity_delta' => 'decimal:6',
            'result_total' => 'decimal:6',
            'result_available' => 'decimal:6',
            'result_not_available' => 'decimal:6',
            'not_available_detail' => 'array',
            'external_references' => 'array',
            'raw' => 'array',
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

    public function channelListingVariant(): BelongsTo
    {
        return $this->belongsTo(ChannelListingVariant::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function marketplaceInbound(): BelongsTo
    {
        return $this->belongsTo(MarketplaceInbound::class);
    }

    public function inboundIdFromReferences(): ?string
    {
        $refs = is_array($this->external_references) ? $this->external_references : [];
        foreach ($refs as $ref) {
            if (! is_array($ref)) {
                continue;
            }
            if (($ref['type'] ?? null) !== 'inbound_id') {
                continue;
            }
            $value = $ref['value'] ?? $ref['id'] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            return (string) $value;
        }

        return null;
    }
}
