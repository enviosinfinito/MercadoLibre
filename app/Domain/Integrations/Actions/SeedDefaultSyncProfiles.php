<?php

namespace App\Domain\Integrations\Actions;

use App\Integrations\Sync\SyncResourceCatalog;
use App\Models\Connection;
use App\Models\ConnectionSyncProfile;
use Illuminate\Support\Facades\DB;

final class SeedDefaultSyncProfiles
{
    public function __construct(
        private readonly SyncResourceCatalog $catalog,
    ) {}

    public function execute(Connection $connection): void
    {
        DB::transaction(function () use ($connection) {
            foreach ($this->catalog->forProvider($connection->provider) as $definition) {
                ConnectionSyncProfile::query()->firstOrCreate(
                    [
                        'connection_id' => $connection->id,
                        'resource_key' => $definition->key,
                    ],
                    [
                        'workspace_id' => $connection->workspace_id,
                        'enabled' => $definition->defaultEnabled,
                        'config' => $definition->resolvedDefaultConfig(),
                    ],
                );
            }
        });
    }
}
