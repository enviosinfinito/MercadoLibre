<?php

namespace App\Services\Export;

use App\Services\Export\Contracts\ExportColumnRegistryInterface;
use App\Services\Export\Contracts\ExportRowProviderInterface;

final class ExportModuleDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ExportColumnRegistryInterface $registry,
        public readonly ExportRowProviderInterface $provider,
        public readonly bool $supportsReferencesFormat = false,
        public readonly bool $supportsRowGranularity = false,
        public readonly bool $requiresPlatformAdmin = false,
    ) {}
}
