<?php

namespace App\Integrations\Ecart\Connector;

use App\Integrations\Contracts\CapabilityMatrix;
use App\Integrations\Contracts\ConnectorInterface;
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
use RuntimeException;
use Throwable;

/**
 * Unified API adapter placeholder (eCart / API2Cart).
 * Third connector — reuses canonical pipeline when implemented.
 */
final class EcartConnector implements ConnectorInterface
{
    public function authorize(array $input = []): AuthResult
    {
        throw new RuntimeException('Ecart connector not fully implemented yet.');
    }

    public function refreshToken(array $credentials = []): TokenResult
    {
        throw new RuntimeException('Ecart connector not fully implemented yet.');
    }

    public function revoke(array $credentials = []): void
    {
        // stub
    }

    public function capabilities(): CapabilityMatrix
    {
        return CapabilityMatrix::make([
            'orders.read' => true,
            'products.read' => true,
            'unified_v2' => true,
        ]);
    }

    public function bootstrap(array $context = []): BootstrapResult
    {
        return new BootstrapResult([
            'stub' => true,
            'capabilities' => $this->capabilities()->capabilities,
        ]);
    }

    public function pull(PullRequest $request): PullResult
    {
        return new PullResult(items: [], nextCursor: ['done' => true]);
    }

    public function fetch(FetchRequest $request): FetchResult
    {
        throw new RuntimeException('Ecart connector not fully implemented yet.');
    }

    public function parseWebhook(WebhookPayload $payload): ParsedWebhook
    {
        throw new RuntimeException('Ecart connector not fully implemented yet.');
    }

    public function push(PushRequest $request): PushResult
    {
        throw new RuntimeException('Ecart connector not fully implemented yet.');
    }

    public function reconcile(ReconcileRequest $request): ReconcileResult
    {
        throw new RuntimeException('Ecart connector not fully implemented yet.');
    }

    public function classifyError(Throwable $e): ClassifiedError
    {
        return new ClassifiedError(category: 'permanent', retryable: false, message: $e->getMessage());
    }
}
