<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
        'workspace_id',
        'disk',
        'path',
        'mime',
        'bytes',
        'checksum',
        'created_by',
])]
class FileAsset extends Model
{
    use BelongsToWorkspace;

    protected $table = 'files';

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

}
