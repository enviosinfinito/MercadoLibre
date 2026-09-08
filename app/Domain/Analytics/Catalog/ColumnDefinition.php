<?php

namespace App\Domain\Analytics\Catalog;

final class ColumnDefinition
{
    /**
     * @param  list<string>  $aggregations
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly string $role,
        public readonly string $sql,
        public readonly array $aggregations = [],
        public readonly bool $filterable = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'role' => $this->role,
            'aggregations' => $this->aggregations,
            'filterable' => $this->filterable,
        ];
    }
}
