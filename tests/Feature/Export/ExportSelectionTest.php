<?php

namespace Tests\Feature\Export;

use App\Domain\Shared\Support\TenantContext;
use App\Models\Connection;
use App\Models\ExportRun;
use App\Models\Order;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExportSelectionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function ids_mode_exports_only_selected_rows(): void
    {
        Storage::fake('local');
        config(['export.disk' => 'local']);

        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        $connection = Connection::factory()->create(['workspace_id' => $workspace->id]);
        TenantContext::set($workspace->id);
        $a = Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'A',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => 10,
            'ordered_at' => now(),
        ]);
        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'B',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => 20,
            'ordered_at' => now(),
        ]);
        TenantContext::clear();

        $response = $this->actingAs($user)
            ->withSession(['workspace_id' => $workspace->id])
            ->postJson(route('exports.start'), [
                'target_module' => 'orders',
                'selection_mode' => 'ids',
                'ids' => [$a->id],
                'columns' => ['external_order_id'],
            ]);

        $response->assertStatus(202);
        $run = ExportRun::query()->where('token', $response->json('token'))->first();
        $this->assertNotNull($run);
        $this->assertSame(1, (int) $run->row_count);
    }

    #[Test]
    public function filter_mode_does_not_require_ids_payload(): void
    {
        Storage::fake('local');
        config(['export.disk' => 'local']);

        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->withSession(['workspace_id' => $workspace->id])
            ->postJson(route('exports.start'), [
                'target_module' => 'orders',
                'selection_mode' => 'filter',
                'filters' => ['status' => 'paid'],
            ])
            ->assertStatus(202)
            ->assertJsonMissingPath('ids');
    }
}
