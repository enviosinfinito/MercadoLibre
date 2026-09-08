<?php

namespace App\Http\Middleware;

use App\Domain\Shared\Support\PublicAppUrl;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep quick tunnels (Cloudflare / ngrok) for OAuth callbacks, webhooks,
 * and guest connection-invite pages. Other browser hits bounce to APP_URL.
 *
 * Tunnel browsers cannot reach the local Vite HMR server, so we force built
 * assets from public/build whenever the request host is a tunnel.
 */
class RedirectTunnelToAppUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());

        if (! $this->isTunnelHost($host)) {
            return $next($request);
        }

        // Vite's public/hot points at localhost — unusable for external guests.
        Vite::useHotFile(storage_path('framework/vite.hot.disabled'));

        if ($request->is('oauth/*/callback', 'webhooks/*', 'connect', 'connect/*', 'up')) {
            return $next($request);
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        if ($appUrl === '') {
            return $next($request);
        }

        return redirect()->to($appUrl.$request->getRequestUri());
    }

    private function isTunnelHost(string $host): bool
    {
        return PublicAppUrl::isTunnelHost($host);
    }
}
