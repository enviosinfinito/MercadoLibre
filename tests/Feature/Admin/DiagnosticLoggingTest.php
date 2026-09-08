<?php

namespace Tests\Feature\Admin;

use App\Domain\Integrations\Actions\RecordSyncHttpLog;
use App\Domain\Platform\DiagnosticHttpLogging;
use App\Integrations\Support\SyncHttpLogContext;
use App\Models\SyncHttpLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DiagnosticLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    #[Test]
    public function non_admin_cannot_access_diagnostic_logging(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.diagnostic-logging.show'))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.diagnostic-logging.update'), ['enabled' => true])
            ->assertForbidden();
    }

    #[Test]
    public function admin_can_enable_and_disable_diagnostic_logging(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $logging = app(DiagnosticHttpLogging::class);

        $this->assertFalse($logging->isEnabled());

        $this->actingAs($admin)
            ->post(route('admin.diagnostic-logging.update'), ['enabled' => true])
            ->assertRedirect(route('admin.diagnostic-logging.show'));

        $this->assertTrue($logging->isEnabled());
        $this->assertGreaterThan(0, $logging->remainingSeconds());

        $this->actingAs($admin)
            ->get(route('admin.diagnostic-logging.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/DiagnosticLogging/Index')
                ->where('status.enabled', true));

        $this->actingAs($admin)
            ->post(route('admin.diagnostic-logging.update'), ['enabled' => false])
            ->assertRedirect(route('admin.diagnostic-logging.show'));

        $this->assertFalse($logging->isEnabled());
    }

    #[Test]
    public function http_logs_are_not_persisted_when_disabled(): void
    {
        $workspace = Workspace::factory()->create();
        SyncHttpLogContext::bind(
            workspaceId: (int) $workspace->id,
            connectionId: null,
            syncRunId: null,
            provider: 'mercadolibre',
            correlationId: 'corr-1',
        );

        try {
            Http::fake([
                'https://example.test/*' => Http::response(['ok' => true], 200),
            ]);

            $started = hrtime(true);
            $response = Http::get('https://example.test/orders');

            app(RecordSyncHttpLog::class)->fromResponse(
                'GET',
                'https://example.test/orders',
                null,
                $response,
                $started,
            );

            $this->assertSame(0, SyncHttpLog::query()->count());
        } finally {
            SyncHttpLogContext::clear();
        }
    }

    #[Test]
    public function http_logs_are_persisted_when_enabled(): void
    {
        app(DiagnosticHttpLogging::class)->enable(10);

        $workspace = Workspace::factory()->create();
        SyncHttpLogContext::bind(
            workspaceId: (int) $workspace->id,
            connectionId: null,
            syncRunId: null,
            provider: 'mercadolibre',
            correlationId: 'corr-2',
        );

        try {
            Http::fake([
                'https://example.test/*' => Http::response(['ok' => true], 200),
            ]);

            $started = hrtime(true);
            $response = Http::get('https://example.test/orders');

            app(RecordSyncHttpLog::class)->fromResponse(
                'GET',
                'https://example.test/orders',
                null,
                $response,
                $started,
            );

            $this->assertSame(1, SyncHttpLog::query()->count());
        } finally {
            SyncHttpLogContext::clear();
        }
    }

    #[Test]
    public function expired_window_does_not_persist_logs(): void
    {
        $logging = app(DiagnosticHttpLogging::class);
        $logging->enable(10);

        \App\Models\PlatformSetting::query()->updateOrCreate(
            ['key' => DiagnosticHttpLogging::SETTING_KEY],
            ['value' => ['enabled_until' => now()->subMinute()->toIso8601String()]],
        );
        \Illuminate\Support\Facades\Cache::forget('platform.diagnostic_http_logging.enabled_until');

        $this->assertFalse($logging->isEnabled());

        $workspace = Workspace::factory()->create();
        SyncHttpLogContext::bind(
            workspaceId: (int) $workspace->id,
            connectionId: null,
            syncRunId: null,
            provider: 'mercadolibre',
            correlationId: 'corr-3',
        );

        try {
            Http::fake([
                'https://example.test/*' => Http::response(['ok' => true], 200),
            ]);

            $started = hrtime(true);
            $response = Http::get('https://example.test/orders');

            app(RecordSyncHttpLog::class)->fromResponse(
                'GET',
                'https://example.test/orders',
                null,
                $response,
                $started,
            );

            $this->assertSame(0, SyncHttpLog::query()->count());
        } finally {
            SyncHttpLogContext::clear();
        }
    }
}
