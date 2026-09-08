<?php

namespace App\Services\Export\Support;

use App\Models\User;
use App\Services\Export\Contracts\ExportColumnRegistryInterface;

abstract class AbstractColumnRegistry implements ExportColumnRegistryInterface
{
    /**
     * @return array<string, array{label: string, tier: string}>
     */
    abstract protected function defineColumns(): array;

    /**
     * @return list<string>
     */
    abstract protected function defineDefaultOrder(): array;

    public function columns(): array
    {
        return $this->defineColumns();
    }

    public function defaultOrder(): array
    {
        return $this->defineDefaultOrder();
    }

    public function getLabel(string $key): ?string
    {
        return $this->columns()[$key]['label'] ?? null;
    }

    public function userCanAccessTier(User $user, string $tier): bool
    {
        if ($tier === self::TIER_GENERAL) {
            return true;
        }

        if ($tier === self::TIER_ADMIN) {
            return (bool) $user->is_platform_admin;
        }

        return false;
    }

    public function filterByUserPermissions(array $keys, User $user): array
    {
        $columns = $this->columns();
        $allowed = [];

        foreach ($keys as $key) {
            if (! is_string($key) || str_starts_with($key, 'formula__')) {
                continue;
            }
            $def = $columns[$key] ?? null;
            if ($def && $this->userCanAccessTier($user, $def['tier'])) {
                $allowed[] = $key;
            }
        }

        return $allowed;
    }

    public function getDefaultColumnsForUser(User $user): array
    {
        return $this->filterByUserPermissions($this->defaultOrder(), $user);
    }

    public function getAvailableColumnsForUser(User $user): array
    {
        $out = [];
        foreach ($this->columns() as $key => $def) {
            if ($this->userCanAccessTier($user, $def['tier'])) {
                $out[] = [
                    'key' => $key,
                    'label' => $def['label'],
                    'tier' => $def['tier'],
                ];
            }
        }

        return $out;
    }
}
