<?php

namespace App\Http\Controllers;

use App\Domain\Integrations\Actions\CreateConnectionInvite;
use App\Domain\Integrations\Actions\RevokeConnectionInvite;
use App\Domain\Shared\Support\TenantContext;
use App\Models\ConnectionInvite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ConnectionInviteController extends Controller
{
    public function store(Request $request, CreateConnectionInvite $create): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'in:mercadolibre'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $invite = $create->execute(
            workspaceId: TenantContext::id(),
            provider: $data['provider'],
            createdBy: $request->user()?->id,
            expiresInDays: (int) ($data['expires_in_days'] ?? 7),
        );

        return redirect()
            ->route('connections.index')
            ->with('success', 'Enlace de conexión generado.')
            ->with('connection_invite_url', $invite->url());
    }

    public function destroy(ConnectionInvite $invite, RevokeConnectionInvite $revoke): RedirectResponse
    {
        abort_unless((int) $invite->workspace_id === TenantContext::id(), 404);

        $revoke->execute($invite);

        return redirect()
            ->route('connections.index')
            ->with('success', 'Enlace de conexión revocado.');
    }
}
