<?php

namespace App\Integrations\Contracts;

use App\Integrations\Contracts\Dto\AuthResult;
use App\Integrations\Contracts\Dto\BootstrapResult;
use App\Integrations\Contracts\Dto\ClassifiedError;
use App\Integrations\Contracts\Dto\FetchRequest;
use App\Integrations\Contracts\Dto\FetchResult;
use App\Integrations\Contracts\Dto\ParsedWebhook;
use App\Integrations\Contracts\Dto\PullRequest;
use App\Integrations\Contracts\Dto\PullResult;
use App\Integrations\Contracts\Dto\PushRequest;
use App\Integrations\Contracts\Dto\PushResult;
use App\Integrations\Contracts\Dto\ReconcileRequest;
use App\Integrations\Contracts\Dto\ReconcileResult;
use App\Integrations\Contracts\Dto\TokenResult;
use App\Integrations\Contracts\Dto\WebhookPayload;
use Throwable;

final class FakeConnector implements ConnectorInterface
{
    public function authorize(array $input = []): AuthResult
    {
        return new AuthResult(['status' => 'authorized', 'fake' => true]);
    }

    public function refreshToken(array $credentials = []): TokenResult
    {
        return new TokenResult([
            'access_token' => 'fake-token',
            'refresh_token' => $credentials['refresh_token'] ?? 'fake-refresh',
            'expires_in' => 21600,
        ]);
    }

    public function revoke(array $credentials = []): void
    {
        // no-op
    }

    public function capabilities(): CapabilityMatrix
    {
        return CapabilityMatrix::make([
            'orders' => true,
            'listings' => true,
            'inventory' => true,
            'webhooks' => true,
            'push' => true,
            'reconcile' => true,
        ]);
    }

    public function bootstrap(array $context = []): BootstrapResult
    {
        return new BootstrapResult(['bootstrapped' => true]);
    }

    public function pull(PullRequest $request): PullResult
    {
        return new PullResult(items: [], nextCursor: null);
    }

    public function fetch(FetchRequest $request): FetchResult
    {
        return new FetchResult([]);
    }

    public function parseWebhook(WebhookPayload $payload): ParsedWebhook
    {
        return new ParsedWebhook(type: 'fake', events: []);
    }

    public function push(PushRequest $request): PushResult
    {
        return new PushResult(['ok' => true]);
    }

    public function reconcile(ReconcileRequest $request): ReconcileResult
    {
        return new ReconcileResult(['ok' => true]);
    }

    public function classifyError(Throwable $e): ClassifiedError
    {
        return new ClassifiedError(
            category: 'fake',
            retryable: false,
            message: $e->getMessage(),
        );
    }
}
