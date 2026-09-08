<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Platform\DiagnosticHttpLogging;
use App\Domain\Shared\Support\AdminAudit;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DiagnosticLoggingController extends Controller
{
    public function show(DiagnosticHttpLogging $logging): Response
    {
        return Inertia::render('Admin/DiagnosticLogging/Index', [
            'status' => $logging->status(),
            'duration_minutes' => (int) config('platform.diagnostic_http_logging_minutes', 10),
        ]);
    }

    public function update(Request $request, DiagnosticHttpLogging $logging): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        if ($data['enabled']) {
            $logging->enable();
            AdminAudit::log($request, 'admin.diagnostic_http_logging.enabled', meta: [
                'enabled_until' => $logging->enabledUntil()?->toIso8601String(),
                'minutes' => (int) config('platform.diagnostic_http_logging_minutes', 10),
            ]);

            return redirect()
                ->route('admin.diagnostic-logging.show')
                ->with('success', 'Registro HTTP activado por '
                    .(int) config('platform.diagnostic_http_logging_minutes', 10)
                    .' minutos.');
        }

        $logging->disable();
        AdminAudit::log($request, 'admin.diagnostic_http_logging.disabled');

        return redirect()
            ->route('admin.diagnostic-logging.show')
            ->with('success', 'Registro HTTP desactivado.');
    }
}
