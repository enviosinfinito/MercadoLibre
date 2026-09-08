<?php

namespace App\Integrations\Sync;

readonly class SyncFieldGroup
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description = '',
        public string $costHint = '',
        public bool $defaultEnabled = false,
    ) {}

    /**
     * @return array{key:string,label:string,description:string,cost_hint:string,default_enabled:bool}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'cost_hint' => $this->costHint,
            'default_enabled' => $this->defaultEnabled,
        ];
    }
}
