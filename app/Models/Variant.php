<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Database\Factories\VariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'product_id',
    'sku',
    'gtin',
    'name',
    'status',
    'base_price_amount',
    'base_price_currency',
])]
class Variant extends Model
{
    /** @use HasFactory<VariantFactory> */
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'base_price_amount' => 'decimal:6',
            'deleted_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function costLayers(): HasMany
    {
        return $this->hasMany(CostLayer::class);
    }

    public function channelListingVariants(): HasMany
    {
        return $this->hasMany(ChannelListingVariant::class);
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }
}
