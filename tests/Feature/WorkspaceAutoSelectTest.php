<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceAutoSelectTest extends TestCase
{
    use RefreshDatabase;

    public function test_connections_page_auto_selects_membership_workspace(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['slug' => 'auto-demo']);

        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('connections.index'));

        $response->assertOk();
        $this->assertEquals($workspace->id, session('workspace_id'));
    }

    public function test_connections_without_membership_still_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('connections.index'))
            ->assertForbidden();
    }
}
