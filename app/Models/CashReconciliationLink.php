<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'connection_id',
    'cash_ledger_entry_id',
    'order_id',
    'marketplace_payment_id',
    'allocated_amount',
    'expected_amount',
    'diff_amount',
    'match_method',
    'status',
    'meta',
])]
class CashReconciliationLink extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'allocated_amount' => 'decimal:6',
            'expected_amount' => 'decimal:6',
            'diff_amount' => 'decimal:6',
            'meta' => 'array',
        ];
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(CashLedgerEntry::class, 'cash_ledger_entry_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function marketplacePayment(): BelongsTo
    {
        return $this->belongsTo(MarketplacePayment::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }
}
