<?php

namespace Tests\Feature\Cash;

use App\Domain\Cash\Support\CashReportSyncProgress;
use App\Jobs\ReconcileCashLedgerJob;
use App\Jobs\SyncMercadoPagoReportJob;
use App\Models\Connection;
use App\Models\EncryptedCredential;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CashReportSyncProgressTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sync_endpoint_starts_progress_and_status_endpoint_returns_it(): void
    {
        Queue::fake();
        [$user, $connection] = $this->memberWithConnection();

        $this->actingAs($user)
            ->withSession(['workspace_id' => $connection->workspace_id])
            ->postJson(route('finance.cash.sync'), [
                'connection_id' => $connection->id,
                'report_kind' => 'settlement',
            ])
            ->assertOk()
            ->assertJsonPath('queued', true)
            ->assertJsonPath('progress.active', true)
            ->assertJsonPath('progress.reports.settlement.phase', 'queued')
            ->assertJsonPath('progress.reports.release.phase', 'queued');

        Queue::assertPushed(SyncMercadoPagoReportJob::class, 2);
        Queue::assertPushed(ReconcileCashLedgerJob::class, 1);

        $this->actingAs($user)
            ->withSession(['workspace_id' => $connection->workspace_id])
            ->getJson(route('finance.cash.sync-status', ['connection_id' => $connection->id]))
            ->assertOk()
            ->assertJsonPath('progress.active', true)
            ->assertJsonStructure(['stats' => ['release_ledger_rows', 'settlement_ledger_rows']]);
    }

    #[Test]
    public function sync_endpoint_chunks_date_range_into_jobs(): void
    {
        Queue::fake();
        config([
            'finance.cash.release_sync_chunk_days' => 2,
            'finance.cash.release_sync_max_days' => 14,
        ]);
        [$user, $connection] = $this->memberWithConnection();

        $this->actingAs($user)
            ->withSession(['workspace_id' => $connection->workspace_id])
            ->postJson(route('finance.cash.sync'), [
                'connection_id' => $connection->id,
                'report_kind' => 'settlement',
                'from' => '2026-08-01',
                'to' => '2026-08-05',
            ])
            ->assertOk()
            ->assertJsonPath('queued', true)
            ->assertJsonPath('windows', 3)
            ->assertJsonPath('from', '2026-08-01')
            ->assertJsonPath('to', '2026-08-05');

        Queue::assertPushed(SyncMercadoPagoReportJob::class, 6);
        Queue::assertPushed(ReconcileCashLedgerJob::class, 1);
        Queue::assertPushed(SyncMercadoPagoReportJob::class, function (SyncMercadoPagoReportJob $job) {
            return $job->reportKind === 'release'
                && is_string($job->fromIso)
                && str_starts_with($job->fromIso, '2026-08-01');
        });
    }

    #[Test]
    public function progress_tracks_waiting_and_done_phases(): void
    {
        $workspace = Workspace::factory()->create();
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
        ]);

        CashReportSyncProgress::start((int) $workspace->id, (int) $connection->id, ['release']);
        CashReportSyncProgress::setReport(
            (int) $workspace->id,
            (int) $connection->id,
            'release',
            'waiting_report',
            'Esperando…',
            ['attempt' => 2, 'max_attempts' => 24],
        );

        $state = CashReportSyncProgress::get((int) $workspace->id, (int) $connection->id);
        $this->assertTrue($state['active']);
        $this->assertSame('waiting_report', $state['reports']['release']['phase']);
        $this->assertSame(2, $state['reports']['release']['attempt']);

        CashReportSyncProgress::setReport(
            (int) $workspace->id,
            (int) $connection->id,
            'release',
            'done',
            'Listo',
            ['rows' => 10, 'created' => 10, 'updated' => 0, 'file_name' => 'x.csv'],
        );
        CashReportSyncProgress::setReconcile(
            (int) $workspace->id,
            (int) $connection->id,
            'done',
            'ok',
        );

        $done = CashReportSyncProgress::get((int) $workspace->id, (int) $connection->id);
        $this->assertFalse($done['active']);
        $this->assertSame('done', $done['reports']['release']['phase']);
        $this->assertSame('x.csv', $done['reports']['release']['file_name']);
    }

    /**
     * @return array{0: User, 1: Connection}
     */
    private function memberWithConnection(): array
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'status' => 'active',
            'token_generation' => 1,
            'needs_reauthorization' => false,
        ]);
        $credential = new EncryptedCredential(['connection_id' => $connection->id]);
        $credential->setPlainPayload([
            'access_token' => 'test-token',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]);
        $credential->save();

        return [$user, $connection];
    }
}
