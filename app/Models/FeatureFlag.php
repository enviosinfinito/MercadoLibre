<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
        'workspace_id',
        'key',
        'enabled',
        'payload',
])]
class FeatureFlag extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'payload' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

}
