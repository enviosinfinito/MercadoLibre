<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'connection_id',
    'order_id',
    'external_payment_id',
    'status',
    'status_detail',
    'transaction_amount',
    'marketplace_fee_amount',
    'shipping_cost_amount',
    'tax_amount',
    'net_received_amount',
    'currency_code',
    'paid_at',
    'money_release_at',
    'is_released',
    'reconciliation_status',
    'expected_net_amount',
    'diff_amount',
    'raw_snapshot_id',
    'payload',
    'provenance',
])]
class MarketplacePayment extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'transaction_amount' => 'decimal:6',
            'marketplace_fee_amount' => 'decimal:6',
            'shipping_cost_amount' => 'decimal:6',
            'tax_amount' => 'decimal:6',
            'net_received_amount' => 'decimal:6',
            'expected_net_amount' => 'decimal:6',
            'diff_amount' => 'decimal:6',
            'paid_at' => 'datetime',
            'money_release_at' => 'datetime',
            'is_released' => 'boolean',
            'payload' => 'array',
            'provenance' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reconciliationLinks(): HasMany
    {
        return $this->hasMany(CashReconciliationLink::class);
    }
}
