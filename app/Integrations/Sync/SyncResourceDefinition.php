<?php

namespace App\Integrations\Sync;

readonly class SyncResourceDefinition
{
    /**
     * @param  list<SyncFieldGroup>  $fieldGroups
     * @param  list<string>  $modes
     * @param  list<string>  $dependsOn
     * @param  array<string, mixed>  $defaultConfig
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $domain,
        public string $description = '',
        public string $costHint = '',
        public bool $defaultEnabled = false,
        public array $fieldGroups = [],
        public array $modes = ['bootstrap', 'webhook'],
        public array $dependsOn = [],
        public array $defaultConfig = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'domain' => $this->domain,
            'description' => $this->description,
            'cost_hint' => $this->costHint,
            'default_enabled' => $this->defaultEnabled,
            'modes' => $this->modes,
            'depends_on' => $this->dependsOn,
            'field_groups' => array_map(
                static fn (SyncFieldGroup $group) => $group->toArray(),
                $this->fieldGroups,
            ),
            'default_config' => $this->resolvedDefaultConfig(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvedDefaultConfig(): array
    {
        $include = [];
        foreach ($this->fieldGroups as $group) {
            $include[$group->key] = $group->defaultEnabled;
        }

        return array_replace_recursive(
            [
                'modes' => $this->modes,
                'include' => $include,
            ],
            $this->defaultConfig,
        );
    }
}
