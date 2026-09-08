<?php

namespace Tests\Feature\Export;

use App\Domain\Shared\Support\TenantContext;
use App\Models\Connection;
use App\Models\Order;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExportReportFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function start_export_generates_xlsx_async_on_sync_queue(): void
    {
        Storage::fake('local');
        config(['export.disk' => 'local', 'queue.default' => 'sync']);

        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role_name' => 'owner',
        ]);

        $connection = Connection::factory()->create(['workspace_id' => $workspace->id]);
        TenantContext::set($workspace->id);
        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'EXT-1',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => 100,
            'ordered_at' => now(),
        ]);
        TenantContext::clear();

        $response = $this->actingAs($user)
            ->withSession(['workspace_id' => $workspace->id])
            ->postJson(route('exports.start'), [
                'target_module' => 'orders',
                'selection_mode' => 'filter',
                'filters' => [],
                'columns' => ['external_order_id', 'total_amount', 'status'],
            ]);

        $response->assertStatus(202)->assertJsonStructure(['token']);
        $token = $response->json('token');

        $status = $this->actingAs($user)
            ->withSession(['workspace_id' => $workspace->id])
            ->getJson(route('exports.status', ['token' => $token]));

        $status->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('progress', 100);

        $this->actingAs($user)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('exports.download', ['token' => $token]))
            ->assertOk();
    }
}
