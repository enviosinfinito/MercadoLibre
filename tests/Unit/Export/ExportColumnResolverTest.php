<?php

namespace Tests\Unit\Export;

use App\Models\ExportPreset;
use App\Models\User;
use App\Models\UserExportPreference;
use App\Models\Workspace;
use App\Services\Export\ExportColumnResolver;
use App\Services\Export\ExportModuleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExportColumnResolverTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function prefers_user_config_over_registry_default(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();

        UserExportPreference::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'target_module' => 'orders',
            'name' => 'Mine',
            'columns' => ['external_order_id', 'total_amount'],
            'is_default' => true,
        ]);

        $resolved = app(ExportColumnResolver::class)->resolveForUser(
            $user,
            (int) $workspace->id,
            'orders',
        );

        $this->assertSame('user_default_config', $resolved['source']);
        $this->assertSame(['external_order_id', 'total_amount'], $resolved['column_keys']);
    }

    #[Test]
    public function prefers_explicit_preset_id(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();

        $preset = ExportPreset::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Admin',
            'target_module' => 'orders',
            'columns' => ['id', 'status'],
            'is_active' => true,
            'is_default' => false,
        ]);

        $resolved = app(ExportColumnResolver::class)->resolveForUser(
            $user,
            (int) $workspace->id,
            'orders',
            $preset->id,
        );

        $this->assertSame('admin_preset', $resolved['source']);
        $this->assertSame(['id', 'status'], $resolved['column_keys']);
    }

    #[Test]
    public function catalog_covers_expected_filter_schemas(): void
    {
        $catalog = app(ExportModuleCatalog::class);
        foreach ($catalog->expectedFilterSchemaModules() as $module) {
            $this->assertContains($module, $catalog->keys(), "Missing export module for schema [{$module}]");
        }
    }
}
