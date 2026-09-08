<?php

namespace App\Domain\Platform;

use App\Models\PlatformSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class DiagnosticHttpLogging
{
    public const SETTING_KEY = 'diagnostic_http_logging';

    private const CACHE_KEY = 'platform.diagnostic_http_logging.enabled_until';

    public function isEnabled(): bool
    {
        $until = $this->enabledUntil();

        return $until !== null && $until->isFuture();
    }

    public function enabledUntil(): ?CarbonImmutable
    {
        $raw = Cache::remember(self::CACHE_KEY, 30, function () {
            $setting = PlatformSetting::query()->where('key', self::SETTING_KEY)->first();

            return $setting?->value['enabled_until'] ?? '';
        });

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    public function remainingSeconds(): int
    {
        $until = $this->enabledUntil();

        if ($until === null || ! $until->isFuture()) {
            return 0;
        }

        return max(0, $until->getTimestamp() - now()->getTimestamp());
    }

    /**
     * @return array{enabled: bool, enabled_until: string|null, remaining_seconds: int}
     */
    public function status(): array
    {
        $until = $this->enabledUntil();
        $enabled = $until !== null && $until->isFuture();

        return [
            'enabled' => $enabled,
            'enabled_until' => $enabled ? $until->toIso8601String() : null,
            'remaining_seconds' => $enabled ? $this->remainingSeconds() : 0,
        ];
    }

    public function enable(?int $minutes = null): void
    {
        $minutes = $minutes ?? (int) config('platform.diagnostic_http_logging_minutes', 10);
        $minutes = max(1, $minutes);

        $until = CarbonImmutable::now()->addMinutes($minutes);

        PlatformSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => ['enabled_until' => $until->toIso8601String()]],
        );

        Cache::forget(self::CACHE_KEY);
        Cache::put(self::CACHE_KEY, $until->toIso8601String(), 30);
    }

    public function disable(): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => ['enabled_until' => null]],
        );

        Cache::forget(self::CACHE_KEY);
        Cache::put(self::CACHE_KEY, '', 30);
    }
}
