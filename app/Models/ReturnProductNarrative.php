<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'product_id',
    'ml_item_id',
    'period_key',
    'summary',
    'reason_breakdown',
    'top_phrases',
    'source',
    'content_hash',
    'cases_analyzed',
    'ai_calls_used',
])]
class ReturnProductNarrative extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'reason_breakdown' => 'array',
            'top_phrases' => 'array',
            'cases_analyzed' => 'integer',
            'ai_calls_used' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
