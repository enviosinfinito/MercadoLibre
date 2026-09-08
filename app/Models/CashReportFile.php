<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'workspace_id',
    'connection_id',
    'report_kind',
    'remote_file_name',
    'storage_path',
    'report_shape',
    'rows_count',
    'bytes',
    'begin_date',
    'end_date',
    'report_id',
])]
class CashReportFile extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'rows_count' => 'integer',
            'bytes' => 'integer',
            'begin_date' => 'datetime',
            'end_date' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function absolutePath(): string
    {
        return Storage::disk('local')->path($this->storage_path);
    }

    public function existsOnDisk(): bool
    {
        return Storage::disk('local')->exists($this->storage_path);
    }
}
