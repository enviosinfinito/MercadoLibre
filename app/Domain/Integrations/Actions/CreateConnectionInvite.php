<?php

namespace App\Domain\Integrations\Actions;

use App\Models\ConnectionInvite;
use App\Models\Workspace;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CreateConnectionInvite
{
    private const ALLOWED_PROVIDERS = ['mercadolibre'];

    public function execute(
        int $workspaceId,
        string $provider,
        ?int $createdBy = null,
        int $expiresInDays = 7,
    ): ConnectionInvite {
        if (! in_array($provider, self::ALLOWED_PROVIDERS, true)) {
            throw new InvalidArgumentException("Provider [{$provider}] does not support connection invites.");
        }

        if (! Workspace::query()->whereKey($workspaceId)->exists()) {
            throw new InvalidArgumentException("Workspace {$workspaceId} does not exist.");
        }

        return ConnectionInvite::query()->create([
            'workspace_id' => $workspaceId,
            'provider' => $provider,
            'token' => Str::random(64),
            'created_by' => $createdBy,
            'expires_at' => now()->addDays(max(1, $expiresInDays)),
        ]);
    }
}
