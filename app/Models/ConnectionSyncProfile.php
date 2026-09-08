<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'connection_id',
    'resource_key',
    'enabled',
    'config',
])]
class ConnectionSyncProfile extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'config' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function includes(string $fieldGroup): bool
    {
        $include = $this->config['include'] ?? [];

        return (bool) ($include[$fieldGroup] ?? false);
    }
}
