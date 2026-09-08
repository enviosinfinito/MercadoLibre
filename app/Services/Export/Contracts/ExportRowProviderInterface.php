<?php

namespace App\Services\Export\Contracts;

use App\Models\User;
use Generator;

interface ExportRowProviderInterface
{
    public function module(): string;

    public function count(int $workspaceId, array $filters, User $user): int;

    /**
     * @param  list<int>|null  $ids
     * @return list<int>
     */
    public function resolveIds(
        int $workspaceId,
        array $filters,
        ?array $ids,
        string $selectionMode,
        User $user,
    ): array;

    /**
     * Yields associative rows keyed by Excel column labels.
     *
     * @param  list<int>  $ids
     * @param  list<string>  $columnKeys  registry keys in export order (no formula keys)
     * @return Generator<int, array<string, mixed>>
     */
    public function iterateRows(
        int $workspaceId,
        array $ids,
        array $columnKeys,
        User $user,
    ): Generator;
}
