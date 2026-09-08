<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'workspace_id',
    'connection_id',
    'order_id',
    'claim_id',
    'external_return_id',
    'status',
    'outcome',
    'reason',
    'reason_id',
    'reason_label',
    'reason_group',
    'buyer_comment',
    'inferred_reason_group',
    'inferred_reason_label',
    'analysis_summary',
    'analysis_confidence',
    'analysis_source',
    'analysis_content_hash',
    'analyzed_at',
    'opened_at',
    'closed_at',
    'ordered_at',
    'delivered_at',
    'days_to_return',
    'returned_amount',
    'currency_code',
    'estimated_loss',
    'dominant_product_id',
    'dominant_ml_item_id',
    'meta',
])]
class ReturnCase extends Model
{
    use BelongsToWorkspace;

    protected $table = 'returns';

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'ordered_at' => 'datetime',
            'delivered_at' => 'datetime',
            'analyzed_at' => 'datetime',
            'returned_amount' => 'decimal:6',
            'estimated_loss' => 'decimal:6',
            'days_to_return' => 'integer',
            'meta' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function dominantProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'dominant_product_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnCaseItem::class, 'return_id');
    }

    public function messageAnalysis(): HasOne
    {
        return $this->hasOne(ReturnMessageAnalysis::class, 'return_id');
    }

    public function financialImpact(): HasOne
    {
        return $this->hasOne(ReturnFinancialImpact::class, 'return_id');
    }
}
