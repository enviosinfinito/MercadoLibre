<?php

namespace App\Domain\Integrations\Actions;

use App\Integrations\Sync\SyncResourceCatalog;
use App\Models\Connection;
use App\Models\ConnectionSyncProfile;
use Illuminate\Support\Collection;

final class ResolveSyncProfile
{
    public function __construct(
        private readonly SyncResourceCatalog $catalog,
        private readonly SeedDefaultSyncProfiles $seedDefaultSyncProfiles,
    ) {}

    public function execute(Connection $connection, string $resourceKey): ConnectionSyncProfile
    {
        $key = $this->catalog->resolveResourceKey($resourceKey);

        $profile = ConnectionSyncProfile::query()
            ->where('connection_id', $connection->id)
            ->where('resource_key', $key)
            ->first();

        if ($profile !== null) {
            return $profile;
        }

        $this->seedDefaultSyncProfiles->execute($connection);

        $profile = ConnectionSyncProfile::query()
            ->where('connection_id', $connection->id)
            ->where('resource_key', $key)
            ->first();

        if ($profile !== null) {
            return $profile;
        }

        $definition = $this->catalog->definition($connection->provider, $key);

        return new ConnectionSyncProfile([
            'workspace_id' => $connection->workspace_id,
            'connection_id' => $connection->id,
            'resource_key' => $key,
            'enabled' => $definition?->defaultEnabled ?? false,
            'config' => $definition?->resolvedDefaultConfig() ?? ['include' => []],
        ]);
    }

    public function isEnabled(Connection $connection, string $resourceKey): bool
    {
        return $this->execute($connection, $resourceKey)->enabled;
    }

    public function includes(Connection $connection, string $resourceKey, string $fieldGroup): bool
    {
        $profile = $this->execute($connection, $resourceKey);

        if (! $profile->enabled) {
            return false;
        }

        return $profile->includes($fieldGroup);
    }

    /**
     * @return Collection<int, ConnectionSyncProfile>
     */
    public function allForConnection(Connection $connection): Collection
    {
        $this->seedDefaultSyncProfiles->execute($connection);

        return ConnectionSyncProfile::query()
            ->where('connection_id', $connection->id)
            ->orderBy('resource_key')
            ->get();
    }
}
