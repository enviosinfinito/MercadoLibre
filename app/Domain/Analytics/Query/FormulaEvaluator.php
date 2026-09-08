<?php

namespace App\Domain\Analytics\Query;

use InvalidArgumentException;

final class FormulaEvaluator
{
    public static function assertSafe(string $formula): void
    {
        $normalized = strtolower(trim($formula));
        if ($normalized === '') {
            throw new InvalidArgumentException('Formula cannot be empty.');
        }

        if (preg_match('/[^a-z0-9_\s\+\-\*\/\(\)\.,]/', $normalized)) {
            throw new InvalidArgumentException('Formula contains unsafe characters.');
        }

        if (preg_match('/\b(select|insert|update|delete|drop|alter|union|sleep|benchmark)\b/', $normalized)) {
            throw new InvalidArgumentException('Formula contains forbidden tokens.');
        }
    }

    /**
     * Evaluate a simple arithmetic formula over aggregate aliases in a result row.
     * Supports sum(field)/sum(field2) style by resolving aliases already computed,
     * or direct alias references: profit_sum/revenue_sum.
     *
     * @param  array<string, mixed>  $row
     */
    public static function evaluate(string $formula, array $row): float|int|null
    {
        self::assertSafe($formula);

        $expr = trim($formula);

        // Replace sum(field) style with matching *_sum aliases or raw keys when present.
        $expr = preg_replace_callback(
            '/\b(sum|avg|min|max|count)\s*\(\s*([a-z0-9_]+)\s*\)/i',
            function (array $m) use ($row) {
                $agg = strtolower($m[1]);
                $field = $m[2];
                $candidates = [
                    "{$field}_{$agg}",
                    $field,
                    "{$agg}_{$field}",
                ];
                foreach ($candidates as $key) {
                    if (array_key_exists($key, $row) && is_numeric($row[$key])) {
                        return (string) (0 + $row[$key]);
                    }
                }

                return '0';
            },
            $expr,
        );

        // Replace bare aliases
        $expr = preg_replace_callback(
            '/\b([a-z_][a-z0-9_]*)\b/i',
            function (array $m) use ($row) {
                $token = $m[1];
                if (in_array(strtolower($token), ['and', 'or', 'not'], true)) {
                    throw new InvalidArgumentException('Logical operators are not allowed in formulas.');
                }
                if (array_key_exists($token, $row) && is_numeric($row[$token])) {
                    return (string) (0 + $row[$token]);
                }
                if (is_numeric($token)) {
                    return $token;
                }

                return '0';
            },
            $expr,
        );

        if (! preg_match('/^[0-9\.\+\-\*\/\(\)\s]+$/', (string) $expr)) {
            throw new InvalidArgumentException('Formula could not be safely evaluated.');
        }

        try {
            // phpcs:ignore Generic.PHP.ForbiddenFunctions
            $result = eval('return (float) ('.$expr.');');
        } catch (\Throwable) {
            return null;
        }

        if (! is_finite((float) $result)) {
            return null;
        }

        return $result;
    }
}
