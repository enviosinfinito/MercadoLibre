<?php

namespace Tests\Feature\Cash;

use App\Domain\Cash\Actions\StoreCashReportFile;
use App\Domain\Cash\Actions\SyncMercadoPagoReport;
use App\Models\CashReportFile;
use App\Models\Connection;
use App\Models\EncryptedCredential;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CashReportFilesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sync_complete_persists_csv_and_cash_report_file_row(): void
    {
        Storage::fake('local');
        $connection = $this->connectionWithToken();

        Http::fake(function (Request $request) {
            $url = $request->url();
            if ($request->method() === 'GET' && str_contains($url, '/release_report/config')) {
                return Http::response([
                    'columns' => array_map(
                        fn ($k) => ['key' => $k],
                        config('finance.mercadopago.release_report_columns'),
                    ),
                ], 200);
            }
            if (str_contains($url, '/release_report/search')) {
                return Http::response(['results' => []], 200);
            }
            if (str_contains($url, '/release_report/list')) {
                return Http::response([[
                    'id' => 42,
                    'status' => 'enabled',
                    'file_name' => 'persist-release.csv',
                    'begin_date' => '2026-08-01T06:00:00Z',
                    'end_date' => '2026-08-12T05:59:59Z',
                ]], 200);
            }
            if (str_contains($url, '/release_report/persist-release.csv')) {
                return Http::response(
                    "DATE,SOURCE_ID,EXTERNAL_REFERENCE,RECORD_TYPE,DESCRIPTION,NET_CREDIT_AMOUNT,NET_DEBIT_AMOUNT,GROSS_AMOUNT,MP_FEE_AMOUNT,TAXES_AMOUNT,PAYMENT_METHOD,BALANCE_AMOUNT\n".
                    "2026-08-10T17:00:00.000-06:00,src-persist,ord-1,release,payment,25.50,0.00,30.00,-2.00,-2.50,visa,100.00\n",
                    200,
                );
            }

            return Http::response(['message' => 'unexpected '.$request->method().' '.$url], 500);
        });

        $result = app(SyncMercadoPagoReport::class)->execute(
            $connection->fresh(['credential']),
            'release',
            waitInline: false,
            reconcileAfter: false,
        );

        $this->assertNotNull($result['local_file_id'] ?? null);
        $this->assertDatabaseHas('cash_report_files', [
            'id' => $result['local_file_id'],
            'workspace_id' => $connection->workspace_id,
            'connection_id' => $connection->id,
            'report_kind' => 'release',
            'remote_file_name' => 'persist-release.csv',
            'report_shape' => 'full',
            'rows_count' => 1,
        ]);

        $file = CashReportFile::query()->findOrFail($result['local_file_id']);
        $this->assertTrue(Storage::disk('local')->exists($file->storage_path));
        $this->assertStringContainsString('src-persist', (string) Storage::disk('local')->get($file->storage_path));
    }

    #[Test]
    public function member_can_list_download_and_preview_report_file(): void
    {
        Storage::fake('local');
        [$user, $connection] = $this->memberWithConnection();

        $file = app(StoreCashReportFile::class)->execute(
            $connection,
            'release',
            'preview-me.csv',
            "DATE,SOURCE_ID,NET_CREDIT_AMOUNT\n2026-08-10T17:00:00.000-06:00,abc,10.00\n",
            'full',
            1,
        );

        $this->actingAs($user)
            ->withSession(['workspace_id' => $connection->workspace_id])
            ->getJson(route('finance.cash.report-files', ['connection_id' => $connection->id]))
            ->assertOk()
            ->assertJsonPath('files.0.id', $file->id)
            ->assertJsonPath('files.0.remote_file_name', 'preview-me.csv');

        $download = $this->actingAs($user)
            ->withSession(['workspace_id' => $connection->workspace_id])
            ->get(route('finance.cash.report-files.download', $file))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString('abc', $download->streamedContent());

        $this->actingAs($user)
            ->withSession(['workspace_id' => $connection->workspace_id])
            ->getJson(route('finance.cash.report-files.preview', $file))
            ->assertOk()
            ->assertJsonPath('total_rows', 1)
            ->assertJsonPath('rows.0.SOURCE_ID', 'abc');
    }

    #[Test]
    public function download_is_404_for_other_workspace_member(): void
    {
        Storage::fake('local');
        [$owner, $connection] = $this->memberWithConnection();
        $file = app(StoreCashReportFile::class)->execute(
            $connection,
            'settlement',
            'secret.csv',
            "DATE,SOURCE_ID\n2026-08-10T10:00:00Z,x\n",
            'reserve',
            1,
        );

        $otherWorkspace = Workspace::factory()->create();
        $otherUser = User::factory()->create();
        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $otherWorkspace->id,
            'user_id' => $otherUser->id,
        ]);

        $this->actingAs($otherUser)
            ->withSession(['workspace_id' => $otherWorkspace->id])
            ->get(route('finance.cash.report-files.download', $file))
            ->assertNotFound();

        $this->actingAs($otherUser)
            ->withSession(['workspace_id' => $otherWorkspace->id])
            ->getJson(route('finance.cash.report-files.preview', $file))
            ->assertNotFound();

        // Sanity: owner still can download.
        $this->actingAs($owner)
            ->withSession(['workspace_id' => $connection->workspace_id])
            ->get(route('finance.cash.report-files.download', $file))
            ->assertOk();
    }

    #[Test]
    public function store_prunes_older_files_beyond_keep_limit(): void
    {
        Storage::fake('local');
        config(['finance.mercadopago.report_files_keep' => 2]);
        $connection = $this->connectionWithToken();

        $ids = [];
        for ($i = 1; $i <= 4; $i++) {
            $ids[] = app(StoreCashReportFile::class)->execute(
                $connection,
                'release',
                "file-{$i}.csv",
                "DATE,SOURCE_ID\n2026-08-0{$i}T10:00:00Z,id{$i}\n",
                'full',
                1,
            )->id;
        }

        $remaining = CashReportFile::query()
            ->where('connection_id', $connection->id)
            ->where('report_kind', 'release')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertCount(2, $remaining);
        $this->assertSame([$ids[2], $ids[3]], $remaining);
    }

    /**
     * @return array{0: User, 1: Connection}
     */
    private function memberWithConnection(): array
    {
        $connection = $this->connectionWithToken();
        $user = User::factory()->create();
        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $connection->workspace_id,
            'user_id' => $user->id,
        ]);

        return [$user, $connection];
    }

    private function connectionWithToken(): Connection
    {
        $workspace = Workspace::factory()->create();
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

        return $connection;
    }
}
