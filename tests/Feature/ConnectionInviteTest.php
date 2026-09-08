<?php

namespace Tests\Feature;

use App\Jobs\BootstrapMercadoLibreListingsJob;
use App\Models\Connection;
use App\Models\ConnectionInvite;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Domain\Shared\Support\PublicAppUrl;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectionInviteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function actingMember(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['name' => 'Acme Store']);

        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$user, $workspace];
    }

    private function fakeMeliTokenExchange(string $userId): void
    {
        config([
            'connectors.mercadolibre.client_id' => 'test-client',
            'connectors.mercadolibre.client_secret' => 'test-secret',
            'connectors.mercadolibre.redirect_uri' => 'https://example.test/oauth/mercadolibre/callback',
            'connectors.mercadolibre.api_base_url' => 'https://api.mercadolibre.com',
        ]);

        Http::fake([
            'https://api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'access-123',
                'refresh_token' => 'refresh-123',
                'expires_in' => 3600,
                'user_id' => $userId,
            ], 200),
            'https://api.mercadolibre.com/users/me' => Http::response([
                'id' => (int) $userId,
                'nickname' => 'SELLER_'.$userId,
                'permalink' => 'https://perfil.mercadolibre.com.mx/SELLER_'.$userId,
                'site_id' => 'MLM',
                'logo' => null,
                'seller_reputation' => [
                    'level_id' => '5_green',
                    'power_seller_status' => 'gold',
                ],
            ], 200),
            'https://api.mercadolibre.com/users/*/brands' => Http::response([
                'brands' => [
                    [
                        'official_store_id' => 1,
                        'picture_url' => 'https://http2.mlstatic.com/brand-'.$userId.'.jpg',
                    ],
                ],
            ], 200),
        ]);
    }

    #[Test]
    public function member_can_create_a_mercadolibre_invite(): void
    {
        [$user, $workspace] = $this->actingMember();

        $this->post(route('connections.invites.store'), [
            'provider' => 'mercadolibre',
        ])
            ->assertRedirect(route('connections.index'))
            ->assertSessionHas('connection_invite_url')
            ->assertSessionHas('success');

        $invite = ConnectionInvite::query()
            ->where('workspace_id', $workspace->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($invite);
        $this->assertSame($workspace->id, $invite->workspace_id);
        $this->assertSame($user->id, $invite->created_by);
        $this->assertSame('mercadolibre', $invite->provider);
        $this->assertTrue($invite->isUsable());
        $this->assertSame(64, strlen($invite->token));
        $this->assertStringStartsWith(PublicAppUrl::base().'/connect/', $invite->url());
    }

    #[Test]
    public function invite_url_uses_public_tunnel_base_when_configured(): void
    {
        config([
            'app.url' => 'http://localhost:8080',
            'app.public_url' => 'https://demo.trycloudflare.com',
        ]);

        $invite = ConnectionInvite::factory()->create([
            'created_by' => null,
        ]);

        $this->assertSame(
            'https://demo.trycloudflare.com/connect/'.$invite->token,
            $invite->url(),
        );
    }

    #[Test]
    public function invite_url_falls_back_to_meli_tunnel_host_when_public_url_empty(): void
    {
        config([
            'app.url' => 'http://localhost:8080',
            'app.public_url' => null,
            'connectors.mercadolibre.redirect_uri' => 'https://abc.trycloudflare.com/oauth/mercadolibre/callback',
        ]);

        $invite = ConnectionInvite::factory()->create([
            'created_by' => null,
        ]);

        $this->assertSame(
            'https://abc.trycloudflare.com/connect/'.$invite->token,
            $invite->url(),
        );
    }

    #[Test]
    public function member_can_revoke_an_invite(): void
    {
        [, $workspace] = $this->actingMember();

        $invite = ConnectionInvite::factory()->create([
            'workspace_id' => $workspace->id,
            'created_by' => null,
        ]);

        $this->delete(route('connections.invites.destroy', $invite))
            ->assertRedirect(route('connections.index'));

        $this->assertNotNull($invite->fresh()->revoked_at);
        $this->assertFalse($invite->fresh()->isUsable());
    }

    #[Test]
    public function guest_can_view_usable_invite_landing(): void
    {
        $workspace = Workspace::factory()->create(['name' => 'Acme Store']);
        $invite = ConnectionInvite::factory()->create([
            'workspace_id' => $workspace->id,
            'created_by' => null,
        ]);

        $this->get(route('connect.show', $invite->token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Connect/Show')
                ->where('workspace_name', 'Acme Store')
                ->where('provider', 'mercadolibre')
                ->where('token', $invite->token));
    }

    #[Test]
    public function guest_is_redirected_for_invalid_or_unusable_tokens(): void
    {
        $workspace = Workspace::factory()->create();

        $this->get(route('connect.show', 'missing-token-value-that-does-not-exist-123456789012'))
            ->assertRedirect(route('connect.done', ['status' => 'invalid']));

        $expired = ConnectionInvite::factory()->expired()->create([
            'workspace_id' => $workspace->id,
            'created_by' => null,
        ]);
        $this->get(route('connect.show', $expired->token))
            ->assertRedirect(route('connect.done', ['status' => 'expired']));

        $used = ConnectionInvite::factory()->used()->create([
            'workspace_id' => $workspace->id,
            'created_by' => null,
        ]);
        $this->get(route('connect.show', $used->token))
            ->assertRedirect(route('connect.done', ['status' => 'used']));

        $revoked = ConnectionInvite::factory()->revoked()->create([
            'workspace_id' => $workspace->id,
            'created_by' => null,
        ]);
        $this->get(route('connect.show', $revoked->token))
            ->assertRedirect(route('connect.done', ['status' => 'revoked']));
    }

    #[Test]
    public function guest_start_redirects_to_mercadolibre_authorize_url(): void
    {
        config([
            'connectors.mercadolibre.client_id' => 'test-client',
            'connectors.mercadolibre.redirect_uri' => 'https://example.test/oauth/mercadolibre/callback',
            'connectors.mercadolibre.auth_base_url' => 'https://auth.mercadolibre.com.mx',
        ]);

        $workspace = Workspace::factory()->create();
        $invite = ConnectionInvite::factory()->create([
            'workspace_id' => $workspace->id,
            'created_by' => null,
        ]);

        $response = $this->get(route('connect.start', $invite->token));
        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertStringContainsString('auth.mercadolibre.com.mx/authorization', $location);
        $this->assertStringContainsString('client_id=test-client', $location);
    }

    #[Test]
    public function invite_oauth_callback_activates_connection_and_marks_invite_used(): void
    {
        Queue::fake();
        $this->fakeMeliTokenExchange('998877');

        $workspace = Workspace::factory()->create();
        $invite = ConnectionInvite::factory()->create([
            'workspace_id' => $workspace->id,
            'created_by' => null,
        ]);

        $state = encrypt([
            'workspace_id' => $workspace->id,
            'invite_id' => $invite->id,
            'user_id' => null,
            'nonce' => 'abc',
        ]);

        $this->get(route('oauth.mercadolibre.callback', [
            'code' => 'auth-code',
            'state' => $state,
        ]))
            ->assertRedirect(PublicAppUrl::to('connect/done').'?status=connected');

        $this->assertNotNull($invite->fresh()->used_at);
        $this->assertDatabaseHas('connections', [
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '998877',
            'status' => 'active',
            'display_name' => 'SELLER_998877',
            'permalink' => 'https://perfil.mercadolibre.com.mx/SELLER_998877',
            'site_id' => 'MLM',
            'avatar_url' => 'https://http2.mlstatic.com/brand-998877.jpg',
            'reputation_level' => '5_green',
            'power_seller_status' => 'gold',
        ]);
        Queue::assertPushed(BootstrapMercadoLibreListingsJob::class);
    }

    #[Test]
    public function authenticated_oauth_callback_still_lands_on_connections(): void
    {
        Queue::fake();
        $this->fakeMeliTokenExchange('112233');

        $workspace = Workspace::factory()->create();

        $state = encrypt([
            'workspace_id' => $workspace->id,
            'user_id' => 1,
            'nonce' => 'abc',
        ]);

        $this->get(route('oauth.mercadolibre.callback', [
            'code' => 'auth-code',
            'state' => $state,
        ]))
            ->assertRedirect(rtrim((string) config('app.url'), '/').'/connections?meli=connected');

        $this->assertDatabaseHas('connections', [
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '112233',
            'status' => 'active',
            'display_name' => 'SELLER_112233',
            'site_id' => 'MLM',
            'avatar_url' => 'https://http2.mlstatic.com/brand-112233.jpg',
            'reputation_level' => '5_green',
            'power_seller_status' => 'gold',
        ]);
        $this->assertSame(
            0,
            ConnectionInvite::query()->where('workspace_id', $workspace->id)->count(),
        );
        $this->assertSame(
            1,
            Connection::query()->where('workspace_id', $workspace->id)->count(),
        );
    }

    #[Test]
    public function connections_index_includes_invites(): void
    {
        [, $workspace] = $this->actingMember();

        $invite = ConnectionInvite::factory()->create([
            'workspace_id' => $workspace->id,
            'created_by' => null,
        ]);

        $this->get(route('connections.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Connections/Index')
                ->has('invites', 1)
                ->where('invites.0.id', $invite->id)
                ->where('invites.0.status', 'active'));
    }
}
