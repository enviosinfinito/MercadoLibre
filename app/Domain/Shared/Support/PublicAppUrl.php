<?php

namespace App\Domain\Shared\Support;

/**
 * Absolute base URL for links shared with people outside the local machine
 * (connection invites, guest post-OAuth landings). Prefer APP_PUBLIC_URL; otherwise
 * reuse the Mercado Libre tunnel host from MELI_REDIRECT_URI when present.
 */
final class PublicAppUrl
{
    public static function base(): string
    {
        $configured = config('app.public_url');
        if (is_string($configured) && trim($configured) !== '') {
            return rtrim($configured, '/');
        }

        $meliRedirect = (string) config('connectors.mercadolibre.redirect_uri', '');
        if ($meliRedirect !== '') {
            $parts = parse_url($meliRedirect);
            $host = strtolower((string) ($parts['host'] ?? ''));
            $scheme = (string) ($parts['scheme'] ?? 'https');

            if ($host !== '' && self::isTunnelHost($host)) {
                return $scheme.'://'.$host;
            }
        }

        return rtrim((string) config('app.url'), '/');
    }

    public static function to(string $path): string
    {
        return self::base().'/'.ltrim($path, '/');
    }

    public static function isTunnelHost(string $host): bool
    {
        $host = strtolower($host);

        return str_contains($host, 'trycloudflare.com')
            || str_contains($host, 'ngrok-free.app')
            || str_contains($host, 'ngrok-free.dev')
            || str_contains($host, 'ngrok.io')
            || str_contains($host, 'ngrok.app');
    }
}
