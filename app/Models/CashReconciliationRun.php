<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'connection_id',
    'period_from',
    'period_to',
    'expected_net_total',
    'settled_net_total',
    'released_net_total',
    'withdrawn_net_total',
    'diff_amount',
    'orders_matched',
    'orders_unmatched',
    'entries_unmatched',
    'status',
    'payload',
    'finished_at',
])]
class CashReconciliationRun extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'period_from' => 'datetime',
            'period_to' => 'datetime',
            'expected_net_total' => 'decimal:6',
            'settled_net_total' => 'decimal:6',
            'released_net_total' => 'decimal:6',
            'withdrawn_net_total' => 'decimal:6',
            'diff_amount' => 'decimal:6',
            'payload' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }
}
