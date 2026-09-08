<?php

namespace App\Services\Export\Support;

final class SimpleColumnRegistry extends AbstractColumnRegistry
{
    /**
     * @param  array<string, array{label: string, tier?: string}|string>  $columns
     * @param  list<string>|null  $defaultOrder
     */
    public function __construct(
        private readonly array $columns,
        private readonly ?array $defaultOrder = null,
    ) {}

    protected function defineColumns(): array
    {
        $out = [];
        foreach ($this->columns as $key => $def) {
            if (is_string($def)) {
                $out[$key] = ['label' => $def, 'tier' => self::TIER_GENERAL];
            } else {
                $out[$key] = [
                    'label' => $def['label'],
                    'tier' => $def['tier'] ?? self::TIER_GENERAL,
                ];
            }
        }

        return $out;
    }

    protected function defineDefaultOrder(): array
    {
        return $this->defaultOrder ?? array_keys($this->defineColumns());
    }
}
