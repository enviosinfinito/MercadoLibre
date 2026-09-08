<?php

namespace Tests\Feature\Analytics;

use App\Models\AnalyticsDashboard;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Database\Seeders\AnalyticsPermissionSeeder;
use Database\Seeders\AnalyticsTemplateSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    #[Test]
    public function platform_templates_are_visible_to_workspace_members(): void
    {
        $this->seed(AnalyticsPermissionSeeder::class);

        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin);
        $this->seed(AnalyticsTemplateSeeder::class);

        $user = User::factory()->create();
        $ws = Workspace::factory()->create();
        WorkspaceMembership::factory()->create([
            'user_id' => $user->id,
            'workspace_id' => $ws->id,
            'role_name' => 'member',
        ]);

        $template = AnalyticsDashboard::query()
            ->where('visibility', AnalyticsDashboard::VISIBILITY_PLATFORM_TEMPLATE)
            ->where('slug', 'overview-comercial')
            ->firstOrFail();

        $this->actingAs($user)
            ->withSession(['workspace_id' => $ws->id])
            ->get(route('analytics.dashboards.show', $template))
            ->assertOk();
    }

    #[Test]
    public function non_admin_cannot_manage_templates(): void
    {
        $user = User::factory()->create(['is_platform_admin' => false]);
        $ws = Workspace::factory()->create();
        WorkspaceMembership::factory()->create([
            'user_id' => $user->id,
            'workspace_id' => $ws->id,
        ]);

        $this->actingAs($user)
            ->get(route('admin.analytics.templates.index'))
            ->assertForbidden();
    }

    #[Test]
    public function user_can_clone_platform_template(): void
    {
        $this->seed(AnalyticsPermissionSeeder::class);
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin);
        $this->seed(AnalyticsTemplateSeeder::class);

        $user = User::factory()->create();
        $ws = Workspace::factory()->create();
        WorkspaceMembership::factory()->create([
            'user_id' => $user->id,
            'workspace_id' => $ws->id,
            'role_name' => 'owner',
        ]);

        $template = AnalyticsDashboard::query()
            ->where('slug', 'pnl-esperado')
            ->firstOrFail();

        $this->actingAs($user)
            ->withSession(['workspace_id' => $ws->id])
            ->post(route('analytics.dashboards.clone', $template), [
                'visibility' => 'personal',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('analytics_dashboards', [
            'workspace_id' => $ws->id,
            'owner_user_id' => $user->id,
            'cloned_from_id' => $template->id,
            'visibility' => 'personal',
        ]);
    }
}
