<?php

namespace Tests\Feature\Cash;

use App\Domain\Cash\Actions\SyncMercadoPagoReport;
use App\Jobs\PollMercadoPagoReportJob;
use App\Jobs\SyncMercadoPagoReportJob;
use App\Models\Connection;
use App\Models\EncryptedCredential;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SyncMercadoPagoReportJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function request_reuses_enabled_file_from_list_without_create(): void
    {
        $connection = $this->connectionWithToken();
        $mpBase = rtrim((string) config('finance.mercadopago.api_base_url', config('connectors.mercadopago.api_base_url')), '/');

        Http::fake(function (Request $request) use ($mpBase) {
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
                return Http::response([
                    [
                        'id' => 99,
                        'status' => 'enabled',
                        'file_name' => 'saas-release-full.csv',
                        'begin_date' => '2026-08-01T06:00:00Z',
                        'end_date' => '2026-08-12T05:59:59Z',
                    ],
                ], 200);
            }
            if (str_contains($url, '/release_report/saas-release-full.csv')) {
                return Http::response(
                    "DATE,SOURCE_ID,EXTERNAL_REFERENCE,RECORD_TYPE,DESCRIPTION,NET_CREDIT_AMOUNT,NET_DEBIT_AMOUNT,GROSS_AMOUNT,MP_FEE_AMOUNT,TAXES_AMOUNT,PAYMENT_METHOD,BALANCE_AMOUNT\n".
                    "2026-08-10T17:00:00.000-06:00,src1,ord1,release,payment,10.00,0.00,12.00,-1.00,-1.00,visa,100.00\n",
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

        $this->assertFalse($result['skipped']);
        $this->assertSame('ready', $result['status']);
        $this->assertSame('saas-release-full.csv', $result['file_name']);
        $this->assertSame('full', $result['report_shape']);
        $this->assertDatabaseHas('cash_ledger_entries', [
            'connection_id' => $connection->id,
            'provenance' => 'mp_release_report',
            'external_source_id' => 'src1',
            'net_amount' => '10.000000',
        ]);
        $this->assertNotNull($result['local_file_id'] ?? null);
        $this->assertDatabaseHas('cash_report_files', [
            'id' => $result['local_file_id'],
            'connection_id' => $connection->id,
            'remote_file_name' => 'saas-release-full.csv',
            'report_kind' => 'release',
        ]);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), $mpBase.'/v1/account/release_report') && ! str_contains($r->url(), 'config'));
    }

    #[Test]
    public function job_dispatches_poll_when_report_pending(): void
    {
        Queue::fake();
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
                return Http::response(['results' => [
                    ['id' => 888, 'status' => 'pending', 'file_name' => ''],
                ]], 200);
            }
            if (str_contains($url, '/release_report/list')) {
                return Http::response([], 200);
            }
            if ($request->method() === 'POST' && str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', '/release_report')) {
                return Http::response(['id' => 888, 'status' => 'pending'], 202);
            }

            return Http::response(['message' => 'unexpected '.$request->method().' '.$url], 500);
        });

        (new SyncMercadoPagoReportJob(
            (int) $connection->workspace_id,
            (int) $connection->id,
            'release',
            null,
            null,
            false,
        ))->handle();

        Queue::assertPushed(PollMercadoPagoReportJob::class, function (PollMercadoPagoReportJob $job) use ($connection) {
            return $job->connectionId === $connection->id
                && $job->reportKind === 'release'
                && $job->attempt === 1;
        });
    }

    #[Test]
    public function ensure_config_puts_when_columns_missing(): void
    {
        $connection = $this->connectionWithToken();
        $putSeen = false;

        Http::fake(function (Request $request) use (&$putSeen) {
            $url = $request->url();
            if ($request->method() === 'GET' && str_contains($url, '/release_report/config')) {
                return Http::response([
                    'columns' => [['key' => 'DATE'], ['key' => 'SOURCE_ID']],
                ], 200);
            }
            if ($request->method() === 'PUT' && str_contains($url, '/release_report/config')) {
                $putSeen = true;

                return Http::response(['ok' => true], 200);
            }
            if (str_contains($url, '/release_report/search') || str_contains($url, '/release_report/list')) {
                return Http::response(['results' => [[
                    'id' => 1,
                    'status' => 'enabled',
                    'file_name' => 'x.csv',
                    'begin_date' => '2020-01-01T00:00:00Z',
                    'end_date' => '2099-01-01T00:00:00Z',
                ]]], 200);
            }
            if (str_contains($url, '/release_report/x.csv')) {
                return Http::response("DATE,SOURCE_ID,EXTERNAL_REFERENCE\n2026-08-10T17:00:00.000-06:00,a,b\n", 200);
            }

            return Http::response(['message' => 'unexpected '.$request->method().' '.$url], 500);
        });

        app(SyncMercadoPagoReport::class)->requestReport($connection->fresh(['credential']), 'release');
        $this->assertTrue($putSeen);
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
