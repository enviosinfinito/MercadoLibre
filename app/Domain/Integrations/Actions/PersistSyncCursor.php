<?php

namespace App\Domain\Integrations\Actions;

use App\Models\Connection;
use App\Models\SyncCursor;

final class PersistSyncCursor
{
    /**
     * @param  array<string, mixed>|string|null  $cursor
     * @param  array<string, mixed>  $meta
     */
    public function execute(
        Connection $connection,
        string $resourceType,
        array|string|null $cursor,
        array $meta = [],
        bool $success = true,
    ): SyncCursor {
        $value = is_array($cursor)
            ? json_encode($cursor, JSON_THROW_ON_ERROR)
            : $cursor;

        return SyncCursor::query()->updateOrCreate(
            [
                'connection_id' => $connection->id,
                'resource_type' => $resourceType,
            ],
            [
                'workspace_id' => $connection->workspace_id,
                'cursor_value' => $value,
                'meta' => $meta === [] ? null : $meta,
                'last_success_at' => $success ? now() : null,
            ],
        );
    }
}
