<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'connection_id',
    'ad_advertiser_id',
    'ad_campaign_id',
    'external_campaign_id',
    'ml_item_id',
    'date',
    'cost',
    'clicks',
    'prints',
    'cpc',
    'ctr',
    'direct_amount',
    'indirect_amount',
    'total_amount',
    'direct_units_quantity',
    'indirect_units_quantity',
    'advertising_items_quantity',
    'organic_units_quantity',
    'organic_units_amount',
    'organic_items_quantity',
    'units_quantity',
    'direct_items_quantity',
    'indirect_items_quantity',
    'acos',
    'roas',
    'currency_code',
    'raw',
])]
class AdSpendDaily extends Model
{
    use BelongsToWorkspace;

    protected $table = 'ad_spend_daily';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'cost' => 'decimal:6',
            'cpc' => 'decimal:6',
            'ctr' => 'decimal:8',
            'direct_amount' => 'decimal:6',
            'indirect_amount' => 'decimal:6',
            'total_amount' => 'decimal:6',
            'direct_units_quantity' => 'decimal:6',
            'indirect_units_quantity' => 'decimal:6',
            'advertising_items_quantity' => 'decimal:6',
            'organic_units_quantity' => 'decimal:6',
            'organic_units_amount' => 'decimal:6',
            'organic_items_quantity' => 'decimal:6',
            'units_quantity' => 'decimal:6',
            'direct_items_quantity' => 'decimal:6',
            'indirect_items_quantity' => 'decimal:6',
            'acos' => 'decimal:8',
            'roas' => 'decimal:8',
            'raw' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function advertiser(): BelongsTo
    {
        return $this->belongsTo(AdAdvertiser::class, 'ad_advertiser_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }
}
