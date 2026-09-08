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

interface ConnectorInterface
{
    public function authorize(array $input = []): AuthResult;

    public function refreshToken(array $credentials = []): TokenResult;

    public function revoke(array $credentials = []): void;

    public function capabilities(): CapabilityMatrix;

    public function bootstrap(array $context = []): BootstrapResult;

    public function pull(PullRequest $request): PullResult;

    public function fetch(FetchRequest $request): FetchResult;

    public function parseWebhook(WebhookPayload $payload): ParsedWebhook;

    public function push(PushRequest $request): PushResult;

    public function reconcile(ReconcileRequest $request): ReconcileResult;

    public function classifyError(Throwable $e): ClassifiedError;
}
