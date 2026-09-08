<?php

namespace App\Domain\Shared\Support;

use RuntimeException;

final class TenantContext
{
    private static ?int $workspaceId = null;

    public static function set(int $workspaceId): void
    {
        self::$workspaceId = $workspaceId;
    }

    public static function get(): ?int
    {
        return self::$workspaceId;
    }

    public static function workspaceId(): ?int
    {
        return self::$workspaceId;
    }

    public static function id(): int
    {
        if (self::$workspaceId === null) {
            throw new RuntimeException('Tenant workspace_id is required but not set.');
        }

        return self::$workspaceId;
    }

    public static function clear(): void
    {
        self::$workspaceId = null;
    }

    public static function has(): bool
    {
        return self::$workspaceId !== null;
    }
}
