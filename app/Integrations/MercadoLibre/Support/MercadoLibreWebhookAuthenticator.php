<?php

namespace App\Integrations\MercadoLibre\Support;

use Illuminate\Http\Request;

/**
 * Authenticity checks for Mercado Libre marketplace notifications.
 *
 * 1. application_id must match MELI_CLIENT_ID / MELI_APP_ID when configured.
 * 2. When MELI_WEBHOOK_SECRET is set: accept either a matching ?secret= query
 *    param (register that URL in the ML console) or a valid x-signature HMAC
 *    (Mercado Pago–style header, when ML sends it).
 */
final class MercadoLibreWebhookAuthenticator
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function authenticate(Request $request, array $payload): bool
    {
        if (! $this->applicationIdMatches($payload)) {
            return false;
        }

        $secret = trim((string) config('connectors.mercadolibre.webhook_secret', ''));
        if ($secret === '') {
            return true;
        }

        if ($this->sharedSecretMatches($request, $secret)) {
            return true;
        }

        $xSignature = (string) $request->header('x-signature', '');
        if ($xSignature !== '' && $this->hmacSignatureMatches($request, $payload, $secret, $xSignature)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applicationIdMatches(array $payload): bool
    {
        $expected = trim((string) config('connectors.mercadolibre.client_id', ''));

        if ($expected === '') {
            return true;
        }

        if (! array_key_exists('application_id', $payload) || $payload['application_id'] === null || $payload['application_id'] === '') {
            return false;
        }

        return (string) $payload['application_id'] === $expected;
    }

    private function sharedSecretMatches(Request $request, string $secret): bool
    {
        $provided = (string) ($request->query('secret')
            ?? $request->header('X-Webhook-Secret')
            ?? '');

        return $provided !== '' && hash_equals($secret, $provided);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hmacSignatureMatches(Request $request, array $payload, string $secret, string $xSignature): bool
    {
        $parts = [];
        foreach (explode(',', $xSignature) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '' || ! str_contains($chunk, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $chunk, 2);
            $parts[trim($key)] = trim($value);
        }

        $ts = $parts['ts'] ?? null;
        $v1 = $parts['v1'] ?? null;
        if (! is_string($ts) || $ts === '' || ! is_string($v1) || $v1 === '') {
            return false;
        }

        $dataId = $request->query('data.id');
        if (! is_string($dataId) || $dataId === '') {
            $dataId = data_get($payload, 'data.id');
        }
        if (! is_string($dataId) || $dataId === '') {
            $resource = $payload['resource'] ?? null;
            if (is_string($resource) && preg_match('#/([^/?]+)$#', $resource, $m)) {
                $dataId = $m[1];
            }
        }

        $requestId = (string) $request->header('x-request-id', '');

        $manifestParts = [];
        if (is_string($dataId) && $dataId !== '') {
            $manifestParts[] = 'id:'.strtolower($dataId);
        }
        if ($requestId !== '') {
            $manifestParts[] = 'request-id:'.$requestId;
        }
        $manifestParts[] = 'ts:'.$ts;
        $manifest = implode(';', $manifestParts).';';

        $computed = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($computed, $v1);
    }
}
