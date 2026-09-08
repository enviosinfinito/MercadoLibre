<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
        'workspace_id',
        'order_id',
        'calculation_version_id',
        'stage',
        'revenue_amount',
        'fees_amount',
        'cogs_amount',
        'profit_amount',
        'currency_code',
        'is_incomplete',
        'payload',
])]
class ProfitSnapshot extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'revenue_amount' => 'decimal:6',
            'fees_amount' => 'decimal:6',
            'cogs_amount' => 'decimal:6',
            'profit_amount' => 'decimal:6',
            'is_incomplete' => 'boolean',
            'payload' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

}
