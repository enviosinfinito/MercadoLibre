<?php

declare(strict_types=1);

namespace Tests\Unit\Filters;

use App\Http\Filters\Inventory\FullOperationsFilterRegistry;
use App\Models\Connection;
use App\Models\FullStockOperation;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FullOperationsFilterRegistryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function exact_operation_type_wins_over_tab(): void
    {
        [$workspace, $connection] = $this->seedWorkspace();

        $inbound = FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'operation_type' => 'INBOUND_RECEPTION',
        ]);
        FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'operation_type' => 'SALE_CONFIRMATION',
        ]);

        $ids = (new FullOperationsFilterRegistry)
            ->apply(FullStockOperation::query()->where('workspace_id', $workspace->id), [
                'tab' => 'sale',
                'operation_type' => 'INBOUND_RECEPTION',
                'q' => '',
                'from' => '',
                'to' => '',
                'connection_id' => null,
            ], $workspace->id)
            ->pluck('id')
            ->all();

        $this->assertSame([$inbound->id], $ids);
    }

    #[Test]
    public function tab_sale_filters_confirmation_only(): void
    {
        [$workspace, $connection] = $this->seedWorkspace();

        FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'operation_type' => 'INBOUND_RECEPTION',
        ]);
        $sale = FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'operation_type' => 'SALE_CONFIRMATION',
        ]);

        $ids = (new FullOperationsFilterRegistry)
            ->apply(FullStockOperation::query()->where('workspace_id', $workspace->id), [
                'tab' => 'sale',
                'operation_type' => '',
                'q' => '',
                'from' => '',
                'to' => '',
                'connection_id' => null,
            ], $workspace->id)
            ->pluck('id')
            ->all();

        $this->assertSame([$sale->id], $ids);
    }

    #[Test]
    public function tab_other_excludes_known_families(): void
    {
        [$workspace, $connection] = $this->seedWorkspace();

        FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'operation_type' => 'SALE_CONFIRMATION',
        ]);
        $custom = FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'operation_type' => 'CUSTOM_UNKNOWN',
        ]);

        $ids = (new FullOperationsFilterRegistry)
            ->apply(FullStockOperation::query()->where('workspace_id', $workspace->id), [
                'tab' => 'other',
                'operation_type' => '',
                'q' => '',
                'from' => '',
                'to' => '',
                'connection_id' => null,
            ], $workspace->id)
            ->pluck('id')
            ->all();

        $this->assertSame([$custom->id], $ids);
    }

    /**
     * @return array{0: Workspace, 1: Connection}
     */
    private function seedWorkspace(): array
    {
        $workspace = Workspace::factory()->create();
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
        ]);

        return [$workspace, $connection];
    }
}
