<?php

namespace App\Integrations\Amazon\Connector;

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
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class AmazonConnector implements ConnectorInterface
{
    public function authorize(array $input = []): AuthResult
    {
        $clientId = config('connectors.amazon.lwa_client_id');
        $redirectUri = $input['redirect_uri'] ?? (rtrim((string) config('app.url'), '/').'/oauth/amazon/callback');
        $state = $input['state'] ?? Str::random(40);
        $marketplaceId = config('connectors.amazon.marketplace_id');

        // Placeholder LWA authorize URL until Seller Central app credentials are configured.
        $url = 'https://sellercentral.amazon.com.mx/apps/authorize/consent?'.http_build_query([
            'application_id' => $clientId ?: 'AMAZON_LWA_CLIENT_ID',
            'state' => $state,
            'version' => 'beta',
            'redirect_uri' => $redirectUri,
            'marketplace_id' => $marketplaceId,
        ]);

        return new AuthResult([
            'authorization_url' => $url,
            'state' => $state,
            'stub' => empty($clientId),
            'message' => empty($clientId)
                ? 'Amazon LWA OAuth placeholder — set AMAZON_LWA_CLIENT_ID'
                : 'Amazon LWA authorize URL generated',
        ]);
    }

    public function refreshToken(array $credentials = []): TokenResult
    {
        throw new RuntimeException('Amazon refreshToken not implemented — configure LWA + SP-API first');
    }

    public function revoke(array $credentials = []): void
    {
        // stub
    }

    public function capabilities(): CapabilityMatrix
    {
        return CapabilityMatrix::make([
            'orders' => true,
            'listings' => true,
            'inventory' => false,
            'webhooks' => false,
            'sqs' => true,
            'push' => true,
            'reconcile' => true,
            'reports' => true,
        ]);
    }

    public function bootstrap(array $context = []): BootstrapResult
    {
        return new BootstrapResult([
            'stub' => true,
            'capabilities' => $this->capabilities()->capabilities,
            'sqs_queue_url' => config('connectors.amazon.sqs_queue_url'),
            'marketplace_id' => config('connectors.amazon.marketplace_id'),
        ]);
    }

    public function pull(PullRequest $request): PullResult
    {
        return new PullResult(items: [], nextCursor: null);
    }

    public function fetch(FetchRequest $request): FetchResult
    {
        // Stub SP-API fetch until credentials + signing are wired.
        return new FetchResult([
            'stub' => true,
            'resource' => $request->resource,
            'external_id' => $request->externalId,
            'marketplace_id' => config('connectors.amazon.marketplace_id'),
            'payload' => [
                'AmazonOrderId' => $request->externalId,
                'OrderStatus' => 'Unshipped',
                'PurchaseDate' => now()->toIso8601String(),
            ],
        ]);
    }

    public function parseWebhook(WebhookPayload $payload): ParsedWebhook
    {
        $body = is_array($payload->body) ? $payload->body : (json_decode((string) $payload->body, true) ?: []);

        // SQS often wraps the notification JSON in a Message string.
        if (isset($body['Message']) && is_string($body['Message'])) {
            $decoded = json_decode($body['Message'], true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        $notificationType = (string) ($body['NotificationType']
            ?? $body['notificationType']
            ?? $body['Type']
            ?? 'UNKNOWN');

        $events = [];

        if (strtoupper($notificationType) === 'ORDER_CHANGE'
            || str_contains(strtoupper($notificationType), 'ORDER')) {
            $orderId = (string) (
                $body['Payload']['OrderChangeNotification']['AmazonOrderId']
                ?? $body['Payload']['OrderId']
                ?? $body['Payload']['AmazonOrderId']
                ?? $body['AmazonOrderId']
                ?? $body['order_id']
                ?? ''
            );

            if ($orderId !== '') {
                $events[] = [
                    'resource_type' => 'order',
                    'external_id' => $orderId,
                    'notification_type' => $notificationType,
                    'payload' => $body['Payload'] ?? $body,
                ];
            }
        }

        return new ParsedWebhook(
            type: $events !== [] ? 'order' : strtolower($notificationType),
            events: $events,
        );
    }

    public function push(PushRequest $request): PushResult
    {
        return new PushResult(['stub' => true, 'resource' => $request->resource]);
    }

    public function reconcile(ReconcileRequest $request): ReconcileResult
    {
        return new ReconcileResult(['stub' => true, 'resource' => $request->resource]);
    }

    public function classifyError(Throwable $e): ClassifiedError
    {
        return new ClassifiedError(
            category: 'unknown',
            retryable: false,
            message: $e->getMessage(),
        );
    }
}
