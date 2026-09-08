<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReturnsDashboardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function authenticated_member_can_open_returns_dashboard(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('returns.index'))
            ->assertOk();
    }
}
