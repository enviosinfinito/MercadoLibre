<?php

namespace App\Integrations\Support;

use App\Domain\Integrations\Actions\RecordSyncHttpLog;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Scoped HTTP client that records request/response audit rows for marketplace sync.
 */
final class LoggedHttpClient
{
    public function __construct(
        private readonly RecordSyncHttpLog $recorder,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     */
    public function get(string $url, array $query = [], ?string $token = null, int $timeout = 30, array $headers = []): Response
    {
        $pending = $this->pending($token, $timeout);
        if ($headers !== []) {
            $pending = $pending->withHeaders($headers);
        }
        $fullUrl = $query === [] ? $url : $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);

        return $this->send('GET', $fullUrl, function () use ($pending, $url, $query) {
            return $pending->get($url, $query);
        }, $query, $headers);
    }

    /**
     * GET that accepts any content type (e.g. claim attachment downloads).
     *
     * @param  array<string, mixed>  $query
     */
    public function getBinary(string $url, array $query = [], ?string $token = null, int $timeout = 60): Response
    {
        $pending = Http::accept('*/*')->timeout($timeout);
        if (is_string($token) && $token !== '') {
            $pending = $pending->withToken($token);
        }
        $fullUrl = $query === [] ? $url : $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);

        return $this->send('GET', $fullUrl, function () use ($pending, $url, $query) {
            return $pending->get($url, $query);
        }, $query, ['Accept' => '*/*']);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function postForm(string $url, array $body, int $timeout = 30): Response
    {
        $pending = Http::asForm()->acceptJson()->timeout($timeout);

        return $this->send('POST', $url, function () use ($pending, $url, $body) {
            return $pending->post($url, $body);
        }, $body, ['Content-Type' => 'application/x-www-form-urlencoded']);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function postJson(string $url, array $body, ?string $token = null, int $timeout = 30, array $headers = []): Response
    {
        $pending = $this->pending($token, $timeout);
        if ($headers !== []) {
            $pending = $pending->withHeaders($headers);
        }

        return $this->send('POST', $url, function () use ($pending, $url, $body) {
            return $pending->post($url, $body);
        }, $body, array_merge(['Content-Type' => 'application/json', 'Accept' => 'application/json'], $headers));
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function putJson(string $url, array $body, ?string $token = null, int $timeout = 30, array $headers = []): Response
    {
        $pending = $this->pending($token, $timeout);
        if ($headers !== []) {
            $pending = $pending->withHeaders($headers);
        }

        return $this->send('PUT', $url, function () use ($pending, $url, $body) {
            return $pending->put($url, $body);
        }, $body, array_merge(['Content-Type' => 'application/json', 'Accept' => 'application/json'], $headers));
    }

    private function pending(?string $token, int $timeout): PendingRequest
    {
        $pending = Http::acceptJson()->timeout($timeout);
        if (is_string($token) && $token !== '') {
            $pending = $pending->withToken($token);
        }

        return $pending;
    }

    /**
     * @param  callable(): Response  $callback
     * @param  array<string, mixed>|string|null  $requestBody
     * @param  array<string, mixed>  $requestHeaders
     */
    private function send(
        string $method,
        string $url,
        callable $callback,
        array|string|null $requestBody = null,
        array $requestHeaders = [],
    ): Response {
        $started = hrtime(true);
        try {
            $response = $callback();
            $this->safeRecord($method, $url, $requestBody, $response, $started, $requestHeaders);

            return $response;
        } catch (Throwable $e) {
            // Still attempt to log if we somehow got a response-less failure.
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>|string|null  $requestBody
     * @param  array<string, mixed>  $requestHeaders
     */
    private function safeRecord(
        string $method,
        string $url,
        array|string|null $requestBody,
        Response $response,
        int $started,
        array $requestHeaders,
    ): void {
        try {
            $headers = $requestHeaders;
            if ($response->transferStats?->getRequest() !== null) {
                // Prefer actual request headers when available.
            }
            $this->recorder->fromResponse(
                $method,
                $url,
                $requestBody,
                $response,
                $started,
                $headers + ['Accept' => 'application/json'],
            );
        } catch (Throwable) {
            // Never break sync because of logging.
        }
    }
}
