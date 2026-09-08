<?php

namespace App\Domain\Analytics\Query;

use App\Domain\Analytics\Catalog\DatasetCatalog;
use App\Domain\Analytics\Catalog\DatasetDefinition;
use App\Domain\Shared\Support\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class QueryEngine
{
    public function __construct(
        private readonly int $cacheTtlSeconds = 30,
    ) {}

    /**
     * @return array{
     *   columns: list<string>,
     *   rows: list<array<string, mixed>>,
     *   meta: array<string, mixed>
     * }
     */
    public function execute(QueryAst $ast, ?int $workspaceId = null): array
    {
        $workspaceId ??= TenantContext::workspaceId();
        if ($workspaceId === null) {
            throw new RuntimeException('Analytics queries require an active workspace.');
        }

        $cacheKey = 'analytics:q:'.$workspaceId.':'.sha1((string) json_encode($ast->toArray()));

        return Cache::remember($cacheKey, $this->cacheTtlSeconds, function () use ($ast, $workspaceId) {
            return $this->run($ast, $workspaceId);
        });
    }

    /**
     * @return array{
     *   columns: list<string>,
     *   rows: list<array<string, mixed>>,
     *   meta: array<string, mixed>
     * }
     */
    public function executeUncached(QueryAst $ast, ?int $workspaceId = null): array
    {
        $workspaceId ??= TenantContext::workspaceId();
        if ($workspaceId === null) {
            throw new RuntimeException('Analytics queries require an active workspace.');
        }

        return $this->run($ast, $workspaceId);
    }

    /**
     * @return array{
     *   columns: list<string>,
     *   rows: list<array<string, mixed>>,
     *   meta: array<string, mixed>
     * }
     */
    private function run(QueryAst $ast, int $workspaceId): array
    {
        $started = hrtime(true);
        $definition = $ast->datasetDefinition();
        $driver = DB::connection()->getDriverName();

        $query = $this->baseQuery($definition);
        $this->applyWhitelistedJoins($query, $definition, $ast->joinDatasets);
        $query->where($definition->workspaceColumn, $workspaceId);

        $selects = [];
        $groupBy = [];
        $aliases = [];

        foreach ($ast->dimensions as $dimension) {
            [$field, $grain] = $ast->splitGrain($dimension);
            $column = $definition->column($field);
            if ($column === null) {
                throw new InvalidArgumentException("Unknown dimension [{$dimension}].");
            }
            $alias = $grain ? "{$field}_{$grain}" : $field;
            $expr = $grain
                ? $this->dateTruncSql($column->sql, $grain, $driver)
                : $column->sql;
            $selects[] = DB::raw("{$expr} as {$alias}");
            $groupBy[] = $expr;
            $aliases[] = $alias;
        }

        $formulaMeasures = [];
        foreach ($ast->measures as $measure) {
            if (isset($measure['formula'])) {
                $formulaMeasures[] = $measure;

                continue;
            }
            $field = (string) $measure['field'];
            $agg = strtolower((string) ($measure['agg'] ?? 'sum'));
            $alias = (string) ($measure['alias'] ?? "{$field}_{$agg}");
            $column = $definition->column($field);
            if ($column === null) {
                throw new InvalidArgumentException("Unknown measure [{$field}].");
            }
            if (! in_array($agg, $column->aggregations, true)) {
                throw new InvalidArgumentException("Aggregation [{$agg}] not allowed on [{$field}].");
            }
            $fn = strtoupper($agg);
            $selects[] = DB::raw("{$fn}({$column->sql}) as {$alias}");
            $aliases[] = $alias;
        }

        if ($selects === []) {
            throw new InvalidArgumentException('Nothing to select.');
        }

        $query->select($selects);

        if ($groupBy !== []) {
            $query->groupByRaw(implode(', ', $groupBy));
        }

        foreach ($ast->filters as $filter) {
            $this->applyFilter($query, $definition, $ast, $filter, $driver);
        }

        foreach ($ast->sort as $sort) {
            $field = (string) ($sort['field'] ?? '');
            $dir = strtolower((string) ($sort['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
            if ($field !== '' && in_array($field, $aliases, true)) {
                $query->orderBy($field, $dir);
            }
        }

        $query->limit($ast->limit);

        $rows = array_map(fn ($row) => (array) $row, $query->get()->all());

        foreach ($formulaMeasures as $measure) {
            $alias = (string) $measure['alias'];
            foreach ($rows as &$row) {
                $row[$alias] = FormulaEvaluator::evaluate((string) $measure['formula'], $row);
            }
            unset($row);
            $aliases[] = $alias;
        }

        $columns = $aliases;
        $resultRows = $rows;

        if (is_array($ast->pivot) && ($ast->pivot['rows'] ?? []) !== []) {
            $pivoted = PivotTransformer::transform($rows, $ast->pivot);
            $columns = $pivoted['columns'];
            $resultRows = $pivoted['rows'];
        }

        return [
            'columns' => $columns,
            'rows' => $resultRows,
            'meta' => [
                'dataset' => $ast->dataset,
                'workspace_id' => $workspaceId,
                'row_count' => count($resultRows),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            ],
        ];
    }

    private function baseQuery(DatasetDefinition $definition): Builder
    {
        $query = DB::table($definition->from);

        foreach ($definition->defaultJoins as $joinSql) {
            if (! preg_match('/^(left|inner)\s+join\s+(\S+)\s+on\s+(.+)$/i', trim($joinSql), $m)) {
                continue;
            }
            $type = strtolower($m[1]) === 'inner' ? 'inner' : 'left';
            $table = $m[2];
            $on = $m[3];
            $query->join($table, function ($join) use ($on) {
                if (preg_match('/^(.+?)\s*=\s*(.+)$/', $on, $parts)) {
                    $join->on(trim($parts[1]), '=', trim($parts[2]));
                }
            }, type: $type);
        }

        return $query;
    }

    /**
     * @param  list<string>  $joinDatasets
     */
    private function applyWhitelistedJoins(
        Builder $query,
        DatasetDefinition $definition,
        array $joinDatasets,
    ): void {
        foreach ($joinDatasets as $joinKey) {
            $joinDef = collect($definition->joins)->firstWhere('dataset', $joinKey);
            if ($joinDef === null) {
                continue;
            }
            $target = DatasetCatalog::get($joinKey);
            $already = collect($definition->defaultJoins)->contains(
                fn (string $j) => str_contains($j, $target->from)
            );
            if ($already) {
                continue;
            }
            foreach ($joinDef['on'] as $on) {
                $query->leftJoin($target->from, $on['local'], '=', $on['foreign']);
            }
        }
    }

    /**
     * @param  array{field: string, op: string, value?: mixed}  $filter
     */
    private function applyFilter(
        Builder $query,
        DatasetDefinition $definition,
        QueryAst $ast,
        array $filter,
        string $driver,
    ): void {
        [$field, $grain] = $ast->splitGrain((string) $filter['field']);
        $column = $definition->column($field);
        if ($column === null || ! $column->filterable) {
            throw new InvalidArgumentException("Cannot filter on [{$field}].");
        }

        $sql = $grain
            ? $this->dateTruncSql($column->sql, $grain, $driver)
            : $column->sql;
        $op = (string) $filter['op'];
        $value = $filter['value'] ?? null;

        match ($op) {
            'eq' => $query->whereRaw("{$sql} = ?", [$value]),
            'neq' => $query->whereRaw("{$sql} <> ?", [$value]),
            'gt' => $query->whereRaw("{$sql} > ?", [$value]),
            'gte' => $query->whereRaw("{$sql} >= ?", [$value]),
            'lt' => $query->whereRaw("{$sql} < ?", [$value]),
            'lte' => $query->whereRaw("{$sql} <= ?", [$value]),
            'like' => $query->whereRaw("{$sql} like ?", ['%'.$value.'%']),
            'in' => $this->whereInRaw($query, $sql, (array) $value, false),
            'not_in' => $this->whereInRaw($query, $sql, (array) $value, true),
            'between' => $query->whereRaw(
                "{$sql} between ? and ?",
                [is_array($value) ? ($value[0] ?? null) : null, is_array($value) ? ($value[1] ?? null) : null],
            ),
            'is_null' => $query->whereRaw("{$sql} is null"),
            'is_not_null' => $query->whereRaw("{$sql} is not null"),
            default => throw new InvalidArgumentException("Unsupported filter op [{$op}]."),
        };
    }

    /**
     * @param  list<mixed>  $values
     */
    private function whereInRaw(Builder $query, string $sql, array $values, bool $not): void
    {
        $values = array_values($values);
        if ($values === []) {
            $query->whereRaw($not ? '1 = 1' : '1 = 0');

            return;
        }
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $query->whereRaw($sql.' '.($not ? 'not in' : 'in')." ({$placeholders})", $values);
    }

    private function dateTruncSql(string $sql, string $grain, string $driver): string
    {
        if ($driver === 'sqlite') {
            return match ($grain) {
                'day' => "strftime('%Y-%m-%d', {$sql})",
                'week' => "strftime('%Y-%W', {$sql})",
                'month' => "strftime('%Y-%m', {$sql})",
                'year' => "strftime('%Y', {$sql})",
                default => "strftime('%Y-%m-%d', {$sql})",
            };
        }

        return match ($grain) {
            'day' => "date({$sql})",
            'week' => "date_format({$sql}, '%x-%v')",
            'month' => "date_format({$sql}, '%Y-%m')",
            'year' => "date_format({$sql}, '%Y')",
            default => "date({$sql})",
        };
    }
}
