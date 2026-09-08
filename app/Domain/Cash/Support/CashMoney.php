<?php

namespace App\Domain\Cash\Support;

final class CashMoney
{
    public static function scale(?string $amount, int $scale = 6): ?string
    {
        if ($amount === null || $amount === '' || ! is_numeric($amount)) {
            return null;
        }

        return bcadd((string) $amount, '0', $scale);
    }

    public static function zero(int $scale = 6): string
    {
        return bcadd('0', '0', $scale);
    }

    public static function add(string $a, string $b, int $scale = 6): string
    {
        return bcadd($a, $b, $scale);
    }

    public static function sub(string $a, string $b, int $scale = 6): string
    {
        return bcsub($a, $b, $scale);
    }

    public static function abs(string $amount, int $scale = 6): string
    {
        return bccomp($amount, '0', $scale) === -1
            ? bcmul($amount, '-1', $scale)
            : bcadd($amount, '0', $scale);
    }

    public static function cmp(string $a, string $b, int $scale = 6): int
    {
        return bccomp($a, $b, $scale);
    }

    public static function withinTolerance(string $diff, ?string $tolerance = null): bool
    {
        $tol = self::scale($tolerance ?? (string) config('finance.cash.diff_tolerance', '0.01')) ?? '0.01';

        return self::cmp(self::abs($diff), $tol) !== 1;
    }

    public static function statusFromDiff(string $diff): string
    {
        if (self::withinTolerance($diff)) {
            return 'balanced';
        }

        return self::cmp($diff, '0') === 1 ? 'short' : 'over';
    }
}
