<?php

namespace App\Services\Export;

final class FormulaEvaluator
{
    /**
     * @param  array<string, mixed>  $rowByLabel
     * @param  array<string, string>  $keyToLabel  registry key => label
     */
    public function evaluate(string $expression, array $rowByLabel, array $keyToLabel): mixed
    {
        $expanded = preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', function ($matches) use ($rowByLabel, $keyToLabel) {
            $columnKey = strtolower((string) ($matches[1] ?? ''));
            $label = $keyToLabel[$columnKey] ?? null;
            $value = $label ? ($rowByLabel[$label] ?? 0) : 0;

            return (string) $this->toNumericValue($value);
        }, $expression);

        if (! is_string($expanded) || trim($expanded) === '') {
            return '-';
        }

        $resolvedFunctions = $this->resolveSupportedFormulaFunctions($expanded);
        if ($resolvedFunctions === null) {
            return '-';
        }

        $result = $this->evaluateArithmeticExpression($resolvedFunctions);
        if ($result === null) {
            return '-';
        }

        return round($result, 6);
    }

    private function toNumericValue(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $normalized = preg_replace('/[^0-9.\-]/', '', $value);
            if ($normalized !== null && $normalized !== '' && is_numeric($normalized)) {
                return (float) $normalized;
            }
        }

        return 0.0;
    }

    private function resolveSupportedFormulaFunctions(string $expression): ?string
    {
        $resolved = $expression;
        $maxIterations = 50;

        for ($i = 0; $i < $maxIterations; $i++) {
            $before = $resolved;

            $resolved = preg_replace_callback('/ROUND\s*\(\s*([^,]+?)\s*,\s*(-?\d+)\s*\)/i', function ($matches) {
                $value = $this->evaluateArithmeticExpression((string) ($matches[1] ?? ''));
                $precision = (int) ($matches[2] ?? 0);
                if ($value === null) {
                    return '0';
                }

                return (string) round($value, $precision);
            }, $resolved) ?? $resolved;

            $resolved = preg_replace_callback('/(FLOOR|CEIL)\s*\(\s*([^)]+?)\s*\)/i', function ($matches) {
                $fn = strtoupper((string) ($matches[1] ?? ''));
                $value = $this->evaluateArithmeticExpression((string) ($matches[2] ?? ''));
                if ($value === null) {
                    return '0';
                }

                return (string) ($fn === 'FLOOR' ? floor($value) : ceil($value));
            }, $resolved) ?? $resolved;

            if ($resolved === $before) {
                break;
            }
        }

        if (preg_match('/\b(ROUND|FLOOR|CEIL)\s*\(/i', $resolved)) {
            return null;
        }

        return $resolved;
    }

    private function evaluateArithmeticExpression(string $expression): ?float
    {
        $math = str_replace('^', '**', $expression);
        if (! preg_match('/^[0-9.\+\-\*\/%\(\)\s]+$/', $math)) {
            return null;
        }

        try {
            $result = @eval('return '.$math.';');
            if (! is_numeric($result) || is_infinite((float) $result) || is_nan((float) $result)) {
                return null;
            }

            return (float) $result;
        } catch (\Throwable) {
            return null;
        }
    }
}
