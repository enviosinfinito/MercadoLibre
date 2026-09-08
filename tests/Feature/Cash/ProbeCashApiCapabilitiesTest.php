<?php

namespace Tests\Feature\Cash;

use App\Domain\Cash\Actions\ProbeCashApiCapabilities;
use App\Models\Connection;
use App\Models\EncryptedCredential;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProbeCashApiCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function report_config_404_then_create_marks_capability_enabled(): void
    {
        $connection = $this->connectionWithToken();

        $mpBase = rtrim((string) config('finance.mercadopago.api_base_url', config('connectors.mercadopago.api_base_url')), '/');
        $mlBase = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');

        Http::fake(function (Request $request) use ($mpBase, $mlBase) {
            $url = $request->url();
            if (str_contains($url, $mlBase.'/collections/search')) {
                return Http::response(['results' => []], 200);
            }
            if (str_contains($url, 'billing/integration')) {
                return Http::response(['message' => 'forbidden'], 403);
            }
            if ($request->method() === 'GET' && str_contains($url, '/settlement_report/config')) {
                return Http::response(['message' => 'not found'], 404);
            }
            if ($request->method() === 'POST' && str_contains($url, '/settlement_report/config')) {
                return Http::response(['file_name_prefix' => 'saas-settlement-report'], 200);
            }
            if ($request->method() === 'GET' && str_contains($url, '/release_report/config')) {
                return Http::response(['message' => 'not found'], 404);
            }
            if ($request->method() === 'POST' && str_contains($url, '/release_report/config')) {
                return Http::response(['file_name_prefix' => 'saas-release-report'], 200);
            }

            return Http::response(['message' => 'unexpected '.$request->method().' '.$url], 500);
        });

        $results = app(ProbeCashApiCapabilities::class)->execute($connection->fresh(['credential']));

        $this->assertTrue($results['collections']['enabled']);
        $this->assertTrue($results['settlement_report']['enabled']);
        $this->assertTrue($results['release_report']['enabled']);
        $this->assertFalse($results['billing']['enabled']);

        $this->assertDatabaseHas('connection_capabilities', [
            'connection_id' => $connection->id,
            'capability_key' => 'cash.mp_settlement_report',
            'enabled' => 1,
        ]);
        $this->assertDatabaseHas('connection_capabilities', [
            'connection_id' => $connection->id,
            'capability_key' => 'cash.mp_release_report',
            'enabled' => 1,
        ]);
    }

    #[Test]
    public function report_config_403_marks_capability_disabled(): void
    {
        $connection = $this->connectionWithToken();

        Http::fake([
            '*/collections/search*' => Http::response(['results' => []], 200),
            '*/settlement_report/config' => Http::response(['message' => 'forbidden'], 403),
            '*/release_report/config' => Http::response(['message' => 'forbidden'], 403),
            '*/billing/*' => Http::response(['message' => 'forbidden'], 403),
        ]);

        $results = app(ProbeCashApiCapabilities::class)->execute($connection->fresh(['credential']));

        $this->assertFalse($results['settlement_report']['enabled']);
        $this->assertFalse($results['release_report']['enabled']);
        $this->assertStringContainsString('sin permiso', (string) $results['settlement_report']['note']);
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
