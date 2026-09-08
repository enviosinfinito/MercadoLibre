<?php

namespace App\Http\Controllers\OAuth;

use App\Domain\Integrations\Actions\ActivateMercadoLibreConnection;
use App\Domain\Integrations\Actions\StartMercadoLibreAuthorization;
use App\Domain\Shared\Support\PublicAppUrl;
use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Integrations\MercadoLibre\Connector\MercadoLibreConnector;
use App\Models\ConnectionInvite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MercadoLibreOAuthController extends Controller
{
    public function redirect(Request $request, StartMercadoLibreAuthorization $startAuth): RedirectResponse
    {
        $workspaceId = TenantContext::workspaceId() ?? $request->session()->get('workspace_id');
        abort_if($workspaceId === null, 403, 'Workspace required');

        return $startAuth->execute(
            request: $request,
            workspaceId: (int) $workspaceId,
            userId: $request->user()?->id,
        );
    }

    public function callback(Request $request, MercadoLibreConnector $connector, ActivateMercadoLibreConnection $activate): RedirectResponse
    {
        $code = $request->query('code');
        $state = $request->query('state');

        $connectionsUrl = rtrim((string) config('app.url'), '/').'/connections';
        // Guest invite landings must stay on the public/tunnel host, not localhost.
        $doneUrl = PublicAppUrl::to('connect/done');

        $inviteId = null;

        try {
            $statePayload = is_string($state) && $state !== '' ? decrypt($state) : null;
            $workspaceId = (int) (is_array($statePayload) ? ($statePayload['workspace_id'] ?? 0) : 0);
            $inviteId = is_array($statePayload) && isset($statePayload['invite_id'])
                ? (int) $statePayload['invite_id']
                : null;
        } catch (\Throwable $e) {
            Log::warning('meli.oauth.invalid_state', ['error' => $e->getMessage()]);

            return $this->failRedirect($inviteId, $connectionsUrl, $doneUrl, 'invalid_state');
        }

        $fromInvite = $inviteId !== null && $inviteId > 0;

        if (! is_string($code) || $code === '') {
            return $this->failRedirect($fromInvite ? $inviteId : null, $connectionsUrl, $doneUrl, 'missing_code');
        }

        if ($workspaceId <= 0) {
            return $this->failRedirect($fromInvite ? $inviteId : null, $connectionsUrl, $doneUrl, 'invalid_workspace');
        }

        $invite = null;
        if ($fromInvite) {
            $invite = ConnectionInvite::query()->find($inviteId);

            if ($invite === null
                || (int) $invite->workspace_id !== $workspaceId
                || $invite->provider !== 'mercadolibre'
                || ! $invite->isUsable()
            ) {
                return redirect()->to($doneUrl.'?status='.($invite?->status() ?? 'invalid'));
            }
        }

        try {
            $token = $connector->exchangeCode($code);
            $payload = $token->payload;

            $activate->execute($workspaceId, [
                'access_token' => $payload['access_token'],
                'refresh_token' => $payload['refresh_token'] ?? null,
                'expires_in' => $payload['expires_in'] ?? null,
                'user_id' => $payload['user_id'] ?? null,
            ]);

            if ($invite !== null) {
                $invite->forceFill(['used_at' => now()])->save();
            }
        } catch (\Throwable $e) {
            Log::warning('meli.oauth.exchange_failed', ['error' => $e->getMessage()]);

            return $this->failRedirect($fromInvite ? $inviteId : null, $connectionsUrl, $doneUrl, 'exchange_failed');
        }

        if ($fromInvite) {
            return redirect()->to($doneUrl.'?status=connected');
        }

        // Land on APP_URL (localhost) so the browser keeps the local session cookie.
        return redirect()->to($connectionsUrl.'?meli=connected');
    }

    private function failRedirect(?int $inviteId, string $connectionsUrl, string $doneUrl, string $reason): RedirectResponse
    {
        if ($inviteId !== null && $inviteId > 0) {
            return redirect()->to($doneUrl.'?status=error&reason='.urlencode($reason));
        }

        return redirect()->to($connectionsUrl.'?meli=error&reason='.urlencode($reason));
    }
}
