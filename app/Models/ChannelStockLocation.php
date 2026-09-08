<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'channel_listing_variant_id',
    'location_type',
    'store_id',
    'network_node_id',
    'quantity',
    'not_available_quantity',
    'stock_version',
    'meta',
    'synced_at',
])]
class ChannelStockLocation extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'not_available_quantity' => 'decimal:6',
            'meta' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function channelListingVariant(): BelongsTo
    {
        return $this->belongsTo(ChannelListingVariant::class);
    }
}
