<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ExportRun;
use App\Models\FullStockOperation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FullOperationsFilteredSelectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Workspace, 1: Connection}
     */
    private function actingMemberWithConnection(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();

        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
            'color' => '#0f766e',
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$workspace, $connection];
    }

    #[Test]
    public function all_ids_respects_tab_filter(): void
    {
        [$workspace, $connection] = $this->actingMemberWithConnection();

        $sale = FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'operation_type' => 'SALE_CONFIRMATION',
            'available_quantity_delta' => '-2',
        ]);
        FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'operation_type' => 'INBOUND_RECEPTION',
            'available_quantity_delta' => '10',
        ]);

        $this->getJson(route('inventory.full-operations.all-ids', ['tab' => 'sale']))
            ->assertOk()
            ->assertJsonPath('total_count', 1)
            ->assertJsonPath('record_ids', [$sale->id]);
    }

    #[Test]
    public function filtered_sums_returns_quantity_deltas(): void
    {
        [$workspace, $connection] = $this->actingMemberWithConnection();

        FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'operation_type' => 'SALE_CONFIRMATION',
            'available_quantity_delta' => '-2.5',
            'not_available_quantity_delta' => '1',
        ]);
        FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'operation_type' => 'SALE_CONFIRMATION',
            'available_quantity_delta' => '-1.5',
            'not_available_quantity_delta' => '0',
        ]);
        FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'operation_type' => 'INBOUND_RECEPTION',
            'available_quantity_delta' => '40',
            'not_available_quantity_delta' => '3',
        ]);

        $this->getJson(route('inventory.full-operations.filtered-sums', ['tab' => 'sale']))
            ->assertOk()
            ->assertJsonPath('total_count', 2)
            ->assertJsonPath('sums_by_key.available_quantity_delta', -4)
            ->assertJsonPath('sums_by_key.not_available_quantity_delta', 1);
    }

    #[Test]
    public function export_start_accepts_full_operations_module(): void
    {
        Storage::fake('local');
        config(['export.disk' => 'local']);

        [$workspace, $connection] = $this->actingMemberWithConnection();

        FullStockOperation::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'operation_type' => 'SALE_CONFIRMATION',
        ]);

        $response = $this->postJson(route('exports.start'), [
            'target_module' => 'full_operations',
            'selection_mode' => 'filter',
            'filters' => ['tab' => 'sale'],
            'columns' => ['external_operation_id', 'operation_type'],
        ]);

        $response->assertStatus(202);
        $run = ExportRun::query()->where('token', $response->json('token'))->first();
        $this->assertNotNull($run);
        $this->assertSame('full_operations', $run->target_module);
    }
}
