<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Query\QueryAst;
use App\Domain\Analytics\Query\QueryEngine;
use App\Domain\Shared\Support\TenantContext;
use App\Models\Connection;
use App\Models\Order;
use App\Models\ProfitSnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QueryEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    #[Test]
    public function it_scopes_aggregations_to_current_workspace(): void
    {
        Cache::flush();

        $wsA = Workspace::factory()->create();
        $wsB = Workspace::factory()->create();

        $connA = Connection::factory()->create(['workspace_id' => $wsA->id]);
        $connB = Connection::factory()->create(['workspace_id' => $wsB->id]);

        $orderA = Order::query()->create([
            'workspace_id' => $wsA->id,
            'connection_id' => $connA->id,
            'external_order_id' => 'A-1',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => 100,
            'ordered_at' => now(),
        ]);
        $orderB = Order::query()->create([
            'workspace_id' => $wsB->id,
            'connection_id' => $connB->id,
            'external_order_id' => 'B-1',
            'status' => 'paid',
            'currency_code' => 'MXN',
            'total_amount' => 999,
            'ordered_at' => now(),
        ]);

        ProfitSnapshot::query()->create([
            'workspace_id' => $wsA->id,
            'order_id' => $orderA->id,
            'stage' => 'expected',
            'revenue_amount' => 100,
            'fees_amount' => 10,
            'cogs_amount' => 20,
            'profit_amount' => 70,
            'currency_code' => 'MXN',
            'is_incomplete' => false,
        ]);
        ProfitSnapshot::query()->create([
            'workspace_id' => $wsB->id,
            'order_id' => $orderB->id,
            'stage' => 'expected',
            'revenue_amount' => 999,
            'fees_amount' => 0,
            'cogs_amount' => 0,
            'profit_amount' => 999,
            'currency_code' => 'MXN',
            'is_incomplete' => false,
        ]);

        TenantContext::set($wsA->id);
        $engine = new QueryEngine(0);
        $result = $engine->executeUncached(QueryAst::fromArray([
            'dataset' => 'profit',
            'dimensions' => [],
            'measures' => [
                ['field' => 'profit', 'agg' => 'sum', 'alias' => 'profit_sum'],
            ],
            'filters' => [],
            'limit' => 10,
        ]), $wsA->id);
        TenantContext::clear();

        $this->assertSame(1, $result['meta']['row_count']);
        $this->assertEquals(70.0, (float) $result['rows'][0]['profit_sum']);
    }

    #[Test]
    public function analytics_index_requires_workspace_membership(): void
    {
        $user = User::factory()->create();
        $ws = Workspace::factory()->create();
        WorkspaceMembership::factory()->create([
            'user_id' => $user->id,
            'workspace_id' => $ws->id,
            'role_name' => 'owner',
        ]);

        $this->actingAs($user)
            ->withSession(['workspace_id' => $ws->id])
            ->get(route('analytics.dashboards.index'))
            ->assertOk();
    }

    #[Test]
    public function query_endpoint_rejects_unknown_dataset(): void
    {
        $user = User::factory()->create();
        $ws = Workspace::factory()->create();
        WorkspaceMembership::factory()->create([
            'user_id' => $user->id,
            'workspace_id' => $ws->id,
        ]);

        $this->actingAs($user)
            ->withSession(['workspace_id' => $ws->id])
            ->postJson(route('analytics.query'), [
                'query' => [
                    'dataset' => 'secrets',
                    'dimensions' => [],
                    'measures' => [['field' => 'x', 'agg' => 'sum']],
                ],
            ])
            ->assertStatus(422);
    }
}
