<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'connection_id',
    'capture_date',
    'captured_at',
    'level_id',
    'power_seller_status',
    'worst_band',
    'claims_rate',
    'cancellations_rate',
    'delayed_handling_rate',
    'sales_completed',
    'is_milestone',
    'milestone_kind',
    'milestone_label',
    'payload',
])]
class ConnectionReputationSnapshot extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'capture_date' => 'date',
            'captured_at' => 'datetime',
            'claims_rate' => 'float',
            'cancellations_rate' => 'float',
            'delayed_handling_rate' => 'float',
            'sales_completed' => 'integer',
            'is_milestone' => 'boolean',
            'payload' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }
}
