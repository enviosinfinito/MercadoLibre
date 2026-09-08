<?php

namespace App\Domain\Integrations\Actions;

use App\Integrations\Sync\SyncResourceCatalog;
use App\Models\Connection;
use App\Models\ConnectionSyncProfile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UpsertSyncProfiles
{
    public function __construct(
        private readonly SyncResourceCatalog $catalog,
        private readonly SeedDefaultSyncProfiles $seedDefaultSyncProfiles,
    ) {}

    /**
     * @param  list<array{resource_key:string,enabled:bool,config?:array<string,mixed>}>  $profiles
     * @return list<ConnectionSyncProfile>
     */
    public function execute(Connection $connection, array $profiles): array
    {
        $definitions = [];
        foreach ($this->catalog->forProvider($connection->provider) as $definition) {
            $definitions[$definition->key] = $definition;
        }

        return DB::transaction(function () use ($connection, $profiles, $definitions) {
            $this->seedDefaultSyncProfiles->execute($connection);

            $saved = [];

            foreach ($profiles as $row) {
                $key = (string) ($row['resource_key'] ?? '');
                if ($key === '' || ! isset($definitions[$key])) {
                    throw new InvalidArgumentException("Invalid sync resource_key: {$key}");
                }

                $definition = $definitions[$key];
                $enabled = (bool) ($row['enabled'] ?? false);
                $incomingConfig = is_array($row['config'] ?? null) ? $row['config'] : [];

                $config = $this->mergeConfig($definition->resolvedDefaultConfig(), $incomingConfig, $definition);

                $profile = ConnectionSyncProfile::query()->updateOrCreate(
                    [
                        'connection_id' => $connection->id,
                        'resource_key' => $key,
                    ],
                    [
                        'workspace_id' => $connection->workspace_id,
                        'enabled' => $enabled,
                        'config' => $config,
                    ],
                );

                $saved[] = $profile;
            }

            return $saved;
        });
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeConfig(array $defaults, array $incoming, \App\Integrations\Sync\SyncResourceDefinition $definition): array
    {
        $allowedIncludeKeys = array_map(
            static fn ($group) => $group->key,
            $definition->fieldGroups,
        );

        $include = $defaults['include'] ?? [];
        if (isset($incoming['include']) && is_array($incoming['include'])) {
            foreach ($incoming['include'] as $key => $value) {
                if (in_array($key, $allowedIncludeKeys, true)) {
                    $include[$key] = (bool) $value;
                }
            }
        }

        $config = array_replace_recursive($defaults, $incoming);
        $config['include'] = $include;

        if (isset($incoming['statuses']) && is_array($incoming['statuses'])) {
            $config['statuses'] = array_values(array_filter(
                $incoming['statuses'],
                static fn ($status) => is_string($status) && $status !== '',
            ));
        }

        if (isset($incoming['modes']) && is_array($incoming['modes'])) {
            $config['modes'] = array_values(array_filter(
                $incoming['modes'],
                static fn ($mode) => is_string($mode) && $mode !== '',
            ));
        }

        if (array_key_exists('lookback_days', $incoming)) {
            $days = (int) $incoming['lookback_days'];
            $config['lookback_days'] = max(1, min(3650, $days > 0 ? $days : 90));
        }

        return $config;
    }
}
