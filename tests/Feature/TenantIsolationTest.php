<?php

namespace Tests\Feature;

use App\Domain\Shared\Support\TenantContext;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function global_scope_hides_other_workspace_rows(): void
    {
        $wsA = Workspace::factory()->create();
        $wsB = Workspace::factory()->create();

        Product::factory()->create(['workspace_id' => $wsA->id, 'name' => 'A']);
        Product::factory()->create(['workspace_id' => $wsB->id, 'name' => 'B']);

        TenantContext::set($wsA->id);
        $names = Product::query()->pluck('name')->all();
        TenantContext::clear();

        $this->assertSame(['A'], $names);
    }

    #[Test]
    public function workspace_middleware_blocks_non_members(): void
    {
        $user = User::factory()->create();
        $ws = Workspace::factory()->create();
        WorkspaceMembership::factory()->create([
            'user_id' => User::factory()->create()->id,
            'workspace_id' => $ws->id,
        ]);

        $this->actingAs($user)
            ->withSession(['workspace_id' => $ws->id])
            ->get(route('products.index'))
            ->assertForbidden();
    }
}
