<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'connection_id',
    'sync_run_id',
    'provider',
    'direction',
    'correlation_id',
    'method',
    'url',
    'endpoint_group',
    'response_status',
    'latency_ms',
    'request_bytes',
    'response_bytes',
    'request_headers_redacted',
    'request_body_redacted',
    'response_headers_redacted',
    'response_body_redacted',
    'error_redacted',
])]
class SyncHttpLog extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'request_headers_redacted' => 'array',
            'response_headers_redacted' => 'array',
            'response_status' => 'integer',
            'latency_ms' => 'integer',
            'request_bytes' => 'integer',
            'response_bytes' => 'integer',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class);
    }

    public function isSuccess(): bool
    {
        $status = $this->response_status;

        return $status !== null && $status >= 200 && $status < 400;
    }
}
