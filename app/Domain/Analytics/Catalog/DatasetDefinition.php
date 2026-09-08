<?php

namespace App\Domain\Analytics\Catalog;

final class DatasetDefinition
{
    /**
     * @param  list<ColumnDefinition>  $columns
     * @param  list<array{dataset: string, on: list<array{local: string, foreign: string}>}>  $joins
     * @param  list<string>  $defaultJoins  SQL join fragments already applied to base query
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly string $from,
        public readonly string $workspaceColumn,
        public readonly array $columns,
        public readonly array $joins = [],
        public readonly array $defaultJoins = [],
    ) {}

    public function column(string $key): ?ColumnDefinition
    {
        foreach ($this->columns as $column) {
            if ($column->key === $key) {
                return $column;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'columns' => array_map(fn (ColumnDefinition $c) => $c->toArray(), $this->columns),
            'joins' => array_map(fn (array $j) => [
                'dataset' => $j['dataset'],
            ], $this->joins),
        ];
    }
}
