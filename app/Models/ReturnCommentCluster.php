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
    'cluster_key',
    'label',
    'representative_phrase',
    'occurrence_count',
    'reason_group',
    'sample_return_ids',
    'meta',
])]
class ReturnCommentCluster extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'occurrence_count' => 'integer',
            'sample_return_ids' => 'array',
            'meta' => 'array',
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
