<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
        'workspace_id',
        'channel_listing_id',
        'variant_id',
        'external_variation_id',
        'user_product_id',
        'inventory_id',
        'sku_external',
        'attribute_combinations',
        'picture_ids',
        'status',
        'price_amount',
        'currency_code',
        'markup_pct',
        'available_quantity',
        'price_synced_at',
        'stock_synced_at',
        'channel_stock_synced_at',
])]
class ChannelListingVariant extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'price_amount' => 'decimal:6',
            'markup_pct' => 'decimal:4',
            'available_quantity' => 'integer',
            'attribute_combinations' => 'array',
            'picture_ids' => 'array',
            'price_synced_at' => 'datetime',
            'stock_synced_at' => 'datetime',
            'channel_stock_synced_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(ChannelListing::class, 'channel_listing_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function stockLocations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ChannelStockLocation::class);
    }

}
