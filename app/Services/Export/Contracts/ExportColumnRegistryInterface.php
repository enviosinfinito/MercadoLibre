<?php

namespace App\Services\Export\Contracts;

use App\Models\User;

interface ExportColumnRegistryInterface
{
    public const TIER_GENERAL = 'general';

    public const TIER_ADMIN = 'admin';

    /**
     * @return array<string, array{label: string, tier: string}>
     */
    public function columns(): array;

    /**
     * @return list<string>
     */
    public function defaultOrder(): array;

    public function getLabel(string $key): ?string;

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function filterByUserPermissions(array $keys, User $user): array;

    /**
     * @return list<string>
     */
    public function getDefaultColumnsForUser(User $user): array;

    /**
     * @return list<array{key: string, label: string, tier: string}>
     */
    public function getAvailableColumnsForUser(User $user): array;
}
