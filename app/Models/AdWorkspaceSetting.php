<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'workspace_id',
    'autopilot_mode',
    'active_preset',
    'kill_switch',
    'write_enabled',
    'write_failures',
    'meta',
])]
class AdWorkspaceSetting extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'kill_switch' => 'boolean',
            'write_enabled' => 'boolean',
            'write_failures' => 'integer',
            'meta' => 'array',
        ];
    }

    public static function forWorkspace(int $workspaceId): self
    {
        return self::query()->firstOrCreate(
            ['workspace_id' => $workspaceId],
            [
                'autopilot_mode' => (string) config('ads.autopilot_mode_default', 'shadow'),
                'kill_switch' => false,
                'write_enabled' => false,
                'write_failures' => 0,
            ],
        );
    }
}
