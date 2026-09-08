<?php

namespace App\Domain\Integrations\Actions;

use App\Integrations\MercadoLibre\Connector\MercadoLibreConnector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StartMercadoLibreAuthorization
{
    public function __construct(
        private readonly MercadoLibreConnector $connector,
    ) {}

    public function execute(
        Request $request,
        int $workspaceId,
        ?int $userId = null,
        ?int $inviteId = null,
    ): RedirectResponse {
        $state = [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'nonce' => bin2hex(random_bytes(8)),
        ];

        if ($inviteId !== null) {
            $state['invite_id'] = $inviteId;
        }

        $auth = $this->connector->authorize([
            'state' => encrypt($state),
        ]);

        $request->session()->put('meli_oauth_state', $auth->payload['state']);

        return redirect()->away($auth->payload['authorization_url']);
    }
}
