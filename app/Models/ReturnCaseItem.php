<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'return_id',
    'order_line_id',
    'product_id',
    'variant_id',
    'channel_listing_id',
    'ml_item_id',
    'ml_variation_id',
    'sku',
    'title',
    'variant_label',
    'quantity',
    'unit_price',
    'line_amount',
    'reason_id',
    'reason_label',
])]
class ReturnCaseItem extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:6',
            'line_amount' => 'decimal:6',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function returnCase(): BelongsTo
    {
        return $this->belongsTo(ReturnCase::class, 'return_id');
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function channelListing(): BelongsTo
    {
        return $this->belongsTo(ChannelListing::class);
    }
}
