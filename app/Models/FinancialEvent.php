<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
        'workspace_id',
        'connection_id',
        'order_id',
        'order_line_id',
        'calculation_version_id',
        'event_type',
        'stage',
        'amount',
        'currency_code',
        'reporting_amount',
        'reporting_currency',
        'occurred_at',
        'provenance',
])]
class FinancialEvent extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:6',
            'reporting_amount' => 'decimal:6',
            'occurred_at' => 'datetime',
            'provenance' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

}
