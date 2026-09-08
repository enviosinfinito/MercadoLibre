<?php

namespace App\Domain\Analytics\Query;

final class PivotTransformer
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array{rows?: list<string>, columns?: list<string>, values?: list<string>}  $pivot
     * @return array{columns: list<string>, rows: list<array<string, mixed>>}
     */
    public static function transform(array $rows, array $pivot): array
    {
        $rowKeys = array_values($pivot['rows'] ?? []);
        $colKeys = array_values($pivot['columns'] ?? []);
        $valueKeys = array_values($pivot['values'] ?? []);

        if ($rowKeys === [] || $valueKeys === []) {
            return [
                'columns' => $rows === [] ? [] : array_keys($rows[0]),
                'rows' => $rows,
            ];
        }

        $columnHeaders = [];
        $grouped = [];

        foreach ($rows as $row) {
            $rowId = self::keyFor($row, $rowKeys);
            if (! isset($grouped[$rowId])) {
                $grouped[$rowId] = [];
                foreach ($rowKeys as $rk) {
                    $grouped[$rowId][$rk] = $row[$rk] ?? null;
                }
            }

            $colId = $colKeys === [] ? 'value' : self::keyFor($row, $colKeys);
            foreach ($valueKeys as $vk) {
                $header = $colKeys === [] ? $vk : $colId.'_'.$vk;
                $columnHeaders[$header] = true;
                $grouped[$rowId][$header] = $row[$vk] ?? null;
            }
        }

        $columns = array_merge($rowKeys, array_keys($columnHeaders));

        return [
            'columns' => $columns,
            'rows' => array_values($grouped),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private static function keyFor(array $row, array $keys): string
    {
        $parts = [];
        foreach ($keys as $key) {
            $parts[] = (string) ($row[$key] ?? '');
        }

        return implode('|', $parts);
    }
}
