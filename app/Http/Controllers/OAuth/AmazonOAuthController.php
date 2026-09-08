<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Integrations\Amazon\Connector\AmazonConnector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AmazonOAuthController extends Controller
{
    public function redirect(Request $request, AmazonConnector $connector): RedirectResponse
    {
        $auth = $connector->authorize([
            'redirect_uri' => route('oauth.amazon.callback'),
            'state' => $request->session()->token(),
        ]);

        $url = $auth->payload['authorization_url'] ?? null;
        if (! is_string($url) || $url === '') {
            return redirect()->route('connections.index')
                ->with('info', 'Amazon OAuth stub: configure LWA credentials to enable.');
        }

        if (! empty($auth->payload['stub'])) {
            return redirect()->away($url);
        }

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        return redirect()->route('connections.index')
            ->with('info', 'Amazon OAuth callback stub received.');
    }
}
