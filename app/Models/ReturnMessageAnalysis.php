<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'return_id',
    'corpus_hash',
    'fingerprint',
    'source',
    'reason_group',
    'reason_label',
    'summary',
    'evidence',
    'signals',
    'model',
    'prompt_tokens',
    'completion_tokens',
    'confidence',
])]
class ReturnMessageAnalysis extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'signals' => 'array',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function returnCase(): BelongsTo
    {
        return $this->belongsTo(ReturnCase::class, 'return_id');
    }
}
