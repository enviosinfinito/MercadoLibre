<?php

namespace Tests\Feature\Admin;

use App\Models\Connection;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    #[Test]
    public function non_admin_cannot_access_admin_area(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.workspaces.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('admin.users.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('admin.connections.index'))
            ->assertForbidden();
    }

    #[Test]
    public function admin_can_list_all_workspaces_and_switch_without_membership(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $wsA = Workspace::factory()->create(['name' => 'Alpha Shop']);
        $wsB = Workspace::factory()->create(['name' => 'Beta Shop']);

        $this->actingAs($admin)
            ->get(route('admin.workspaces.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Workspaces/Index')
                ->has('workspaces.data', 2));

        $this->actingAs($admin)
            ->post(route('admin.workspaces.switch', $wsB))
            ->assertRedirect(route('dashboard'));

        $this->assertEquals($wsB->id, session('workspace_id'));

        $this->actingAs($admin)
            ->get(route('workspaces.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('workspaces', 2));

        $this->assertNotNull($wsA);
    }

    #[Test]
    public function admin_can_create_update_and_soft_delete_workspace(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin)
            ->post(route('admin.workspaces.store'), [
                'name' => 'New Co',
                'slug' => 'new-co',
                'reporting_currency' => 'MXN',
                'default_costing_method' => 'fifo',
            ])
            ->assertRedirect();

        $workspace = Workspace::query()->where('slug', 'new-co')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('admin.workspaces.update', $workspace), [
                'name' => 'New Co Updated',
                'slug' => 'new-co',
                'reporting_currency' => 'USD',
                'default_costing_method' => 'fifo',
            ])
            ->assertRedirect(route('admin.workspaces.show', $workspace));

        $this->assertSame('New Co Updated', $workspace->fresh()->name);
        $this->assertSame('USD', $workspace->fresh()->reporting_currency);

        $this->actingAs($admin)
            ->delete(route('admin.workspaces.destroy', $workspace))
            ->assertRedirect(route('admin.workspaces.index'));

        $this->assertSoftDeleted($workspace);

        $this->actingAs($admin)
            ->post(route('admin.workspaces.restore', $workspace->id))
            ->assertRedirect(route('admin.workspaces.show', $workspace));

        $this->assertNull($workspace->fresh()->deleted_at);
    }

    #[Test]
    public function admin_can_manage_users_and_memberships(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $workspace = Workspace::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
                'is_platform_admin' => false,
            ])
            ->assertRedirect();

        $user = User::query()->where('email', 'jane@example.com')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('admin.users.update', $user), [
                'name' => 'Jane Admin',
                'email' => 'jane@example.com',
                'is_platform_admin' => true,
            ])
            ->assertRedirect(route('admin.users.show', $user));

        $this->assertTrue($user->fresh()->is_platform_admin);

        $this->actingAs($admin)
            ->post(route('admin.users.memberships.store', $user), [
                'workspace_id' => $workspace->id,
                'role_name' => 'owner',
            ])
            ->assertRedirect();

        $membership = WorkspaceMembership::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspace->id)
            ->firstOrFail();

        $this->assertSame('owner', $membership->role_name);

        $this->actingAs($admin)
            ->put(route('admin.users.memberships.update', [$user, $membership]), [
                'role_name' => 'admin',
            ])
            ->assertRedirect();

        $this->assertSame('admin', $membership->fresh()->role_name);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Users/Index')
                ->has('users.data'));
    }

    #[Test]
    public function admin_can_list_and_disconnect_connections_across_tenants(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $wsA = Workspace::factory()->create();
        $wsB = Workspace::factory()->create();

        $connA = Connection::factory()->create([
            'workspace_id' => $wsA->id,
            'external_user_id' => '111',
        ]);
        $connB = Connection::factory()->create([
            'workspace_id' => $wsB->id,
            'external_user_id' => '222',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.connections.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Connections/Index')
                ->has('connections.data', 2));

        $this->actingAs($admin)
            ->put(route('admin.connections.update', $connA), [
                'status' => 'disabled',
                'needs_reauthorization' => true,
            ])
            ->assertRedirect(route('admin.connections.show', $connA));

        $this->assertSame('disabled', $connA->fresh()->status);
        $this->assertTrue($connA->fresh()->needs_reauthorization);

        $this->actingAs($admin)
            ->delete(route('admin.connections.destroy', $connB))
            ->assertRedirect(route('admin.connections.index'));

        $this->assertDatabaseMissing('connections', ['id' => $connB->id]);
    }

    #[Test]
    public function admin_cannot_delete_own_account(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $admin))
            ->assertStatus(422);
    }
}
