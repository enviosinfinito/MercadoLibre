<?php

namespace Tests\Feature;

use App\Domain\Automation\Actions\EvaluateRuleTemplates;
use App\Domain\PostSale\Actions\ResolveOrderPostSaleOutcome;
use App\Models\Alert;
use App\Models\Connection;
use App\Models\Order;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HighReturnRateAlertTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Workspace, 1: Connection}
     */
    private function workspaceWithConnection(): array
    {
        $workspace = Workspace::factory()->create();
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);

        return [$workspace, $connection];
    }

    #[Test]
    public function creates_alert_when_return_rate_exceeds_threshold(): void
    {
        [$workspace, $connection] = $this->workspaceWithConnection();

        for ($i = 0; $i < 9; $i++) {
            Order::query()->create([
                'workspace_id' => $workspace->id,
                'connection_id' => $connection->id,
                'external_order_id' => "OK-{$i}",
                'status' => 'paid',
                'currency_code' => 'MXN',
                'total_amount' => '100',
                'ordered_at' => now()->subDay(),
            ]);
        }

        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'RET-0',
            'status' => 'delivered',
            'post_sale_outcome' => ResolveOrderPostSaleOutcome::RETURNED,
            'currency_code' => 'MXN',
            'total_amount' => '100',
            'ordered_at' => now()->subDay(),
        ]);

        // 1/10 = 10% >= 8% threshold
        $created = app(EvaluateRuleTemplates::class)->execute($workspace->id);

        $this->assertTrue($created->contains(fn (Alert $a) => $a->context['template'] === 'high_return_rate'));
        $this->assertDatabaseHas('alerts', [
            'workspace_id' => $workspace->id,
            'title' => 'Tasa de devoluciones alta',
            'status' => 'open',
        ]);
    }

    #[Test]
    public function does_not_create_alert_below_threshold_or_min_orders(): void
    {
        [$workspace, $connection] = $this->workspaceWithConnection();

        for ($i = 0; $i < 20; $i++) {
            Order::query()->create([
                'workspace_id' => $workspace->id,
                'connection_id' => $connection->id,
                'external_order_id' => "OK-{$i}",
                'status' => 'paid',
                'currency_code' => 'MXN',
                'total_amount' => '100',
                'ordered_at' => now()->subDay(),
            ]);
        }

        Order::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_order_id' => 'RET-LOW',
            'status' => 'delivered',
            'post_sale_outcome' => ResolveOrderPostSaleOutcome::RETURNED,
            'currency_code' => 'MXN',
            'total_amount' => '100',
            'ordered_at' => now()->subDay(),
        ]);

        // 1/21 ≈ 4.8% < 8%
        app(EvaluateRuleTemplates::class)->execute($workspace->id);

        $this->assertDatabaseMissing('alerts', [
            'workspace_id' => $workspace->id,
            'title' => 'Tasa de devoluciones alta',
        ]);
    }

    #[Test]
    public function does_not_create_alert_when_order_floor_not_met(): void
    {
        [$workspace, $connection] = $this->workspaceWithConnection();

        for ($i = 0; $i < 3; $i++) {
            Order::query()->create([
                'workspace_id' => $workspace->id,
                'connection_id' => $connection->id,
                'external_order_id' => "RET-{$i}",
                'status' => 'delivered',
                'post_sale_outcome' => ResolveOrderPostSaleOutcome::RETURNED,
                'currency_code' => 'MXN',
                'total_amount' => '100',
                'ordered_at' => now()->subDay(),
            ]);
        }

        app(EvaluateRuleTemplates::class)->execute($workspace->id);

        $this->assertDatabaseMissing('alerts', [
            'workspace_id' => $workspace->id,
            'title' => 'Tasa de devoluciones alta',
        ]);
    }
}
