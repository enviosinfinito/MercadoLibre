<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectionsIndexTest extends TestCase
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
    public function index_includes_existing_workspace_connections(): void
    {
        [, $workspace] = $this->actingMember();

        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '112184176',
            'site_id' => 'MLM',
            'display_name' => 'TIENDA_DEMO',
            'permalink' => 'https://perfil.mercadolibre.com.mx/TIENDA_DEMO',
            'avatar_url' => 'https://http2.mlstatic.com/demo-avatar.jpg',
            'reputation_level' => '5_green',
            'power_seller_status' => 'platinum',
            'status' => 'active',
        ]);

        Connection::factory()->create([
            'workspace_id' => Workspace::factory()->create()->id,
            'provider' => 'mercadolibre',
        ]);

        $this->get(route('connections.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Connections/Index')
                ->has('connections', 1)
                ->where('connections.0.id', $connection->id)
                ->where('connections.0.provider', 'mercadolibre')
                ->where('connections.0.external_user_id', '112184176')
                ->where('connections.0.display_name', 'TIENDA_DEMO')
                ->where('connections.0.permalink', 'https://perfil.mercadolibre.com.mx/TIENDA_DEMO')
                ->where('connections.0.avatar_url', 'https://http2.mlstatic.com/demo-avatar.jpg')
                ->where('connections.0.reputation_level', '5_green')
                ->where('connections.0.power_seller_status', 'platinum')
                ->where('connections.0.color', $connection->color)
                ->has('platforms')
                ->has('invites'));
    }

    #[Test]
    public function index_returns_empty_connections_for_new_workspace(): void
    {
        $this->actingMember();

        $this->get(route('connections.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Connections/Index')
                ->has('connections', 0)
                ->has('platforms')
                ->has('invites', 0));
    }
}
