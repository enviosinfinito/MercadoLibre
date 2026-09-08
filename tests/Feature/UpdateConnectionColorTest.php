<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UpdateConnectionColorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function actingMember(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();

        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$user, $workspace];
    }

    #[Test]
    public function member_can_update_connection_color(): void
    {
        [, $workspace] = $this->actingMember();

        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'color' => '#0f766e',
        ]);

        $this->patch(route('connections.color.update', $connection), [
            'color' => '#DC2626',
        ])->assertRedirect();

        $this->assertDatabaseHas('connections', [
            'id' => $connection->id,
            'color' => '#dc2626',
        ]);
    }

    #[Test]
    public function member_can_update_connection_color_via_json(): void
    {
        [, $workspace] = $this->actingMember();

        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'color' => '#0f766e',
        ]);

        $this->patchJson(route('connections.color.update', $connection), [
            'color' => '#0284c7',
        ])
            ->assertOk()
            ->assertJson([
                'id' => $connection->id,
                'color' => '#0284c7',
            ]);
    }

    #[Test]
    public function invalid_color_is_rejected(): void
    {
        [, $workspace] = $this->actingMember();

        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'color' => '#0f766e',
        ]);

        $this->patch(route('connections.color.update', $connection), [
            'color' => 'red',
        ])->assertSessionHasErrors('color');

        $this->assertDatabaseHas('connections', [
            'id' => $connection->id,
            'color' => '#0f766e',
        ]);
    }

    #[Test]
    public function cannot_update_color_of_other_workspace_connection(): void
    {
        $this->actingMember();

        $other = Connection::factory()->create([
            'workspace_id' => Workspace::factory()->create()->id,
            'color' => '#0f766e',
        ]);

        $this->patch(route('connections.color.update', $other), [
            'color' => '#dc2626',
        ])->assertNotFound();
    }
}
