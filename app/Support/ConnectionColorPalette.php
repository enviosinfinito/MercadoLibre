<?php

namespace App\Support;

use App\Models\Connection;

final class ConnectionColorPalette
{
    /**
     * Curated palette — keep in sync with resources/js/lib/connectionColor.js
     *
     * @var list<string>
     */
    public const COLORS = [
        '#0f766e',
        '#0284c7',
        '#d97706',
        '#7c3aed',
        '#dc2626',
        '#059669',
        '#ea580c',
        '#4f46e5',
    ];

    public static function isValid(?string $color): bool
    {
        if ($color === null) {
            return false;
        }

        return (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', $color);
    }

    public static function normalize(string $color): string
    {
        return strtolower($color);
    }

    /**
     * Next unused palette color for a workspace, cycling if all are taken.
     */
    public static function nextForWorkspace(int $workspaceId, ?int $excludeConnectionId = null): string
    {
        $used = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->when($excludeConnectionId !== null, fn ($q) => $q->where('id', '!=', $excludeConnectionId))
            ->whereNotNull('color')
            ->pluck('color')
            ->map(fn ($c) => strtolower((string) $c))
            ->all();

        foreach (self::COLORS as $color) {
            if (! in_array(strtolower($color), $used, true)) {
                return $color;
            }
        }

        $count = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->when($excludeConnectionId !== null, fn ($q) => $q->where('id', '!=', $excludeConnectionId))
            ->count();

        return self::COLORS[$count % count(self::COLORS)];
    }
}
