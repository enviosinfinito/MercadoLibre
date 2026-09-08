<?php

namespace App\Domain\Analytics\Query;

use App\Domain\Analytics\Catalog\DatasetCatalog;
use App\Domain\Analytics\Catalog\DatasetDefinition;
use InvalidArgumentException;

final class QueryAst
{
    public const MAX_LIMIT = 5000;

    public const ALLOWED_OPS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'between', 'like', 'is_null', 'is_not_null'];

    /**
     * @param  list<string>  $dimensions
     * @param  list<array{field?: string, agg?: string, alias?: string, formula?: string}>  $measures
     * @param  list<array{field: string, op: string, value?: mixed}>  $filters
     * @param  list<array{field: string, dir?: string}>  $sort
     * @param  list<string>  $joinDatasets
     * @param  array{rows?: list<string>, columns?: list<string>, values?: list<string>}|null  $pivot
     */
    public function __construct(
        public readonly string $dataset,
        public readonly array $dimensions = [],
        public readonly array $measures = [],
        public readonly array $filters = [],
        public readonly array $sort = [],
        public readonly int $limit = 1000,
        public readonly ?array $pivot = null,
        public readonly array $joinDatasets = [],
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $dataset = (string) ($input['dataset'] ?? '');
        if ($dataset === '') {
            throw new InvalidArgumentException('Query AST requires dataset.');
        }

        DatasetCatalog::get($dataset);

        $dimensions = array_values(array_map('strval', $input['dimensions'] ?? []));
        $measures = array_values($input['measures'] ?? []);
        $filters = array_values($input['filters'] ?? []);
        $sort = array_values($input['sort'] ?? []);
        $joinDatasets = array_values(array_map('strval', $input['join_datasets'] ?? []));
        $limit = min(max(1, (int) ($input['limit'] ?? 1000)), self::MAX_LIMIT);
        $pivot = isset($input['pivot']) && is_array($input['pivot']) ? $input['pivot'] : null;

        $ast = new self($dataset, $dimensions, $measures, $filters, $sort, $limit, $pivot, $joinDatasets);
        $ast->validate();

        return $ast;
    }

    public function validate(): void
    {
        $definition = DatasetCatalog::get($this->dataset);

        if ($this->dimensions === [] && $this->measures === []) {
            throw new InvalidArgumentException('Query must include at least one dimension or measure.');
        }

        foreach ($this->dimensions as $dimension) {
            $this->assertDimension($definition, $dimension);
        }

        foreach ($this->measures as $measure) {
            if (! is_array($measure)) {
                throw new InvalidArgumentException('Invalid measure definition.');
            }
            if (isset($measure['formula'])) {
                FormulaEvaluator::assertSafe((string) $measure['formula']);
                if (empty($measure['alias'])) {
                    throw new InvalidArgumentException('Formula measures require an alias.');
                }

                continue;
            }
            $field = (string) ($measure['field'] ?? '');
            $agg = strtolower((string) ($measure['agg'] ?? 'sum'));
            $column = $definition->column($field);
            if ($column === null) {
                throw new InvalidArgumentException("Invalid measure field [{$field}].");
            }
            if (! in_array($agg, $column->aggregations, true)) {
                throw new InvalidArgumentException("Aggregation [{$agg}] not allowed on [{$field}].");
            }
        }

        foreach ($this->filters as $filter) {
            if (! is_array($filter) || empty($filter['field']) || empty($filter['op'])) {
                throw new InvalidArgumentException('Invalid filter definition.');
            }
            $field = (string) $filter['field'];
            $op = (string) $filter['op'];
            if (! in_array($op, self::ALLOWED_OPS, true)) {
                throw new InvalidArgumentException("Filter op [{$op}] is not allowed.");
            }
            [$base] = $this->splitGrain($field);
            if ($definition->column($base) === null) {
                throw new InvalidArgumentException("Unknown filter field [{$field}].");
            }
        }

        foreach ($this->joinDatasets as $joinKey) {
            $allowed = collect($definition->joins)->pluck('dataset')->all();
            if (! in_array($joinKey, $allowed, true)) {
                throw new InvalidArgumentException("Join to [{$joinKey}] is not allowed from [{$this->dataset}].");
            }
        }
    }

    public function datasetDefinition(): DatasetDefinition
    {
        return DatasetCatalog::get($this->dataset);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    public function splitGrain(string $dimension): array
    {
        if (str_contains($dimension, ':')) {
            [$field, $grain] = explode(':', $dimension, 2);

            return [$field, $grain];
        }

        return [$dimension, null];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dataset' => $this->dataset,
            'dimensions' => $this->dimensions,
            'measures' => $this->measures,
            'filters' => $this->filters,
            'sort' => $this->sort,
            'limit' => $this->limit,
            'pivot' => $this->pivot,
            'join_datasets' => $this->joinDatasets,
        ];
    }

    private function assertDimension(DatasetDefinition $definition, string $dimension): void
    {
        [$field, $grain] = $this->splitGrain($dimension);
        $column = $definition->column($field);
        if ($column === null) {
            throw new InvalidArgumentException("Unknown dimension [{$dimension}].");
        }
        if ($grain !== null && $column->type !== 'date') {
            throw new InvalidArgumentException("Grain [{$grain}] only allowed on date dimensions.");
        }
        if ($grain !== null && ! in_array($grain, ['day', 'week', 'month', 'year'], true)) {
            throw new InvalidArgumentException("Unsupported date grain [{$grain}].");
        }
    }
}
