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
    'entry_type',
    'transaction_type',
    'external_source_id',
    'external_order_id',
    'external_reference',
    'external_shipping_id',
    'gross_amount',
    'fee_amount',
    'shipping_fee_amount',
    'tax_amount',
    'financing_fee_amount',
    'net_amount',
    'currency_code',
    'occurred_at',
    'released_at',
    'is_released',
    'provenance',
    'idempotency_key',
    'payload',
])]
class CashLedgerEntry extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:6',
            'fee_amount' => 'decimal:6',
            'shipping_fee_amount' => 'decimal:6',
            'tax_amount' => 'decimal:6',
            'financing_fee_amount' => 'decimal:6',
            'net_amount' => 'decimal:6',
            'occurred_at' => 'datetime',
            'released_at' => 'datetime',
            'is_released' => 'boolean',
            'payload' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function reconciliationLinks(): HasMany
    {
        return $this->hasMany(CashReconciliationLink::class);
    }
}
