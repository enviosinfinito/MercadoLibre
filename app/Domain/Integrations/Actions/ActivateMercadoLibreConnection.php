<?php

namespace App\Domain\Integrations\Actions;

use App\Jobs\BootstrapMercadoLibreListingsJob;
use App\Jobs\ProbeCashApiCapabilitiesJob;
use App\Models\Connection;
use App\Models\EncryptedCredential;
use App\Models\Workspace;
use App\Support\ConnectionColorPalette;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ActivateMercadoLibreConnection
{
    public function __construct(
        private readonly EnsureWebhookSubscriptions $ensureWebhookSubscriptions,
        private readonly SeedDefaultSyncProfiles $seedDefaultSyncProfiles,
        private readonly ResolveSyncProfile $resolveSyncProfile,
        private readonly EnrichMercadoLibreConnectionProfile $enrichProfile,
    ) {}

    /**
     * @param  array{access_token:string,refresh_token?:string,expires_in?:int,user_id?:string|int,site_id?:string}  $tokens
     */
    public function execute(int $workspaceId, array $tokens, ?Connection $connection = null): Connection
    {
        if (! Workspace::query()->whereKey($workspaceId)->exists()) {
            throw new InvalidArgumentException("Workspace {$workspaceId} does not exist.");
        }

        $connection = DB::transaction(function () use ($workspaceId, $tokens, $connection) {
            $externalUserId = isset($tokens['user_id']) ? (string) $tokens['user_id'] : null;

            $connection ??= Connection::query()->firstOrNew([
                'workspace_id' => $workspaceId,
                'provider' => 'mercadolibre',
                'external_user_id' => $externalUserId,
                'site_id' => $tokens['site_id'] ?? null,
            ]);

            $connection->fill([
                'workspace_id' => $workspaceId,
                'provider' => 'mercadolibre',
                'external_user_id' => $externalUserId,
                'site_id' => $tokens['site_id'] ?? $connection->site_id,
                'status' => 'active',
                'needs_reauthorization' => false,
                'last_error_redacted' => null,
            ]);

            if (! ConnectionColorPalette::isValid($connection->color)) {
                $connection->color = ConnectionColorPalette::nextForWorkspace(
                    $workspaceId,
                    $connection->exists ? (int) $connection->id : null,
                );
            }

            $connection->token_generation = ((int) $connection->token_generation) + 1;
            $connection->save();

            $payload = [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'expires_in' => $tokens['expires_in'] ?? null,
                'expires_at' => isset($tokens['expires_in'])
                    ? now()->addSeconds((int) $tokens['expires_in'])->toIso8601String()
                    : null,
                'user_id' => $externalUserId,
            ];

            EncryptedCredential::query()->updateOrCreate(
                ['connection_id' => $connection->id],
                ['payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR))],
            );

            $this->ensureWebhookSubscriptions->execute($connection);
            $this->seedDefaultSyncProfiles->execute($connection);

            return $connection->fresh(['credential']);
        });

        $connection = $this->enrichProfile->execute(
            $connection,
            is_string($tokens['access_token'] ?? null) ? $tokens['access_token'] : null,
        );

        if ($this->resolveSyncProfile->isEnabled($connection, 'listings')) {
            BootstrapMercadoLibreListingsJob::dispatch(
                (int) $connection->workspace_id,
                (int) $connection->id,
            );
        }

        ProbeCashApiCapabilitiesJob::dispatch(
            (int) $connection->workspace_id,
            (int) $connection->id,
        );

        return $connection;
    }
}
