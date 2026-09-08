<?php

namespace App\Http\Controllers;

use App\Domain\Integrations\Actions\StartMercadoLibreAuthorization;
use App\Models\ConnectionInvite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConnectController extends Controller
{
    public function show(string $token): Response|RedirectResponse
    {
        $invite = $this->findInvite($token);

        if ($invite === null) {
            return redirect()->route('connect.done', ['status' => 'invalid']);
        }

        if (! $invite->isUsable()) {
            return redirect()->route('connect.done', ['status' => $invite->status()]);
        }

        $invite->loadMissing('workspace:id,name');

        return Inertia::render('Connect/Show', [
            'token' => $invite->token,
            'provider' => $invite->provider,
            'provider_label' => $this->providerLabel($invite->provider),
            'workspace_name' => $invite->workspace?->name,
            'expires_at' => $invite->expires_at?->toIso8601String(),
            'start_url' => '/connect/'.$invite->token.'/start',
        ]);
    }

    public function start(
        Request $request,
        string $token,
        StartMercadoLibreAuthorization $startAuth,
    ): RedirectResponse {
        $invite = $this->findInvite($token);

        if ($invite === null) {
            return redirect()->route('connect.done', ['status' => 'invalid']);
        }

        if (! $invite->isUsable()) {
            return redirect()->route('connect.done', ['status' => $invite->status()]);
        }

        if ($invite->provider !== 'mercadolibre') {
            return redirect()->route('connect.done', ['status' => 'unsupported_provider']);
        }

        return $startAuth->execute(
            request: $request,
            workspaceId: (int) $invite->workspace_id,
            userId: null,
            inviteId: (int) $invite->id,
        );
    }

    public function done(Request $request): Response
    {
        $status = (string) $request->query('status', 'unknown');
        $reason = $request->query('reason');

        $messages = [
            'connected' => 'La cuenta de Mercado Libre se conectó correctamente. Ya puedes cerrar esta ventana.',
            'invalid' => 'Este enlace de conexión no es válido.',
            'expired' => 'Este enlace de conexión expiró. Solicita uno nuevo.',
            'used' => 'Este enlace de conexión ya fue utilizado.',
            'revoked' => 'Este enlace de conexión fue revocado.',
            'unsupported_provider' => 'Este proveedor no está disponible para conexión por enlace.',
            'error' => 'No se pudo completar la conexión'.(is_string($reason) && $reason !== '' ? " ({$reason})" : '').'. Intenta de nuevo con un enlace nuevo.',
            'unknown' => 'Estado de conexión desconocido.',
        ];

        $tone = $status === 'connected' ? 'success' : 'error';

        return Inertia::render('Connect/Done', [
            'status' => $status,
            'tone' => $tone,
            'message' => $messages[$status] ?? $messages['unknown'],
        ]);
    }

    private function findInvite(string $token): ?ConnectionInvite
    {
        if ($token === '' || strlen($token) > 64) {
            return null;
        }

        return ConnectionInvite::query()
            ->where('token', $token)
            ->first();
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'mercadolibre' => 'Mercado Libre',
            default => $provider,
        };
    }
}
