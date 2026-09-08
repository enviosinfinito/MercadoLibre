<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'order_id',
    'connection_id',
    'variant_id',
    'channel_listing_variant_id',
    'external_item_id',
    'external_variation_id',
    'sku',
    'title',
    'quantity',
    'unit_price_amount',
    'currency_code',
    'line_total_amount',
    'match_status',
])]
class OrderLine extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'unit_price_amount' => 'decimal:6',
            'line_total_amount' => 'decimal:6',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function channelListingVariant(): BelongsTo
    {
        return $this->belongsTo(ChannelListingVariant::class);
    }
}
