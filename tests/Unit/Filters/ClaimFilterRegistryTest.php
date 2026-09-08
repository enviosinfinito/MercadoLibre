<?php

declare(strict_types=1);

namespace Tests\Unit\Filters;

use App\Http\Filters\Claims\ClaimFilterRegistry;
use App\Models\Claim;
use App\Models\Connection;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClaimFilterRegistryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_filters_by_status(): void
    {
        [$workspace, $connection] = $this->seedWorkspace();

        $opened = Claim::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_claim_id' => 'C-OPEN',
            'status' => 'opened',
            'reason' => 'damaged',
        ]);
        Claim::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_claim_id' => 'C-CLOSED',
            'status' => 'closed',
            'reason' => 'other',
        ]);

        $ids = (new ClaimFilterRegistry)
            ->apply(Claim::query()->where('workspace_id', $workspace->id), [
                'status' => 'opened',
                'q' => '',
            ])
            ->pluck('id')
            ->all();

        $this->assertSame([$opened->id], $ids);
    }

    #[Test]
    public function it_filters_by_search_query(): void
    {
        [$workspace, $connection] = $this->seedWorkspace();

        $match = Claim::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_claim_id' => 'CLAIM-UNIQUE-99',
            'status' => 'opened',
            'reason' => 'shipping',
            'reason_id' => 'PDD1',
            'type' => 'mediations',
            'stage' => 'claim',
        ]);
        Claim::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_claim_id' => 'OTHER',
            'status' => 'opened',
            'reason' => 'other',
        ]);

        $ids = (new ClaimFilterRegistry)
            ->apply(Claim::query()->where('workspace_id', $workspace->id), [
                'status' => '',
                'q' => 'UNIQUE-99',
            ])
            ->pluck('id')
            ->all();

        $this->assertSame([$match->id], $ids);
    }

    #[Test]
    public function it_returns_all_when_filters_empty(): void
    {
        [$workspace, $connection] = $this->seedWorkspace();

        Claim::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_claim_id' => 'A',
            'status' => 'opened',
        ]);
        Claim::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'external_claim_id' => 'B',
            'status' => 'closed',
        ]);

        $count = (new ClaimFilterRegistry)
            ->apply(Claim::query()->where('workspace_id', $workspace->id), [
                'status' => '',
                'q' => '',
            ])
            ->count();

        $this->assertSame(2, $count);
    }

    /**
     * @return array{0: Workspace, 1: Connection}
     */
    private function seedWorkspace(): array
    {
        $workspace = Workspace::factory()->create();
        $connection = Connection::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'external_user_id' => '1',
            'status' => 'active',
            'token_generation' => 1,
        ]);

        return [$workspace, $connection];
    }
}
