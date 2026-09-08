<?php

namespace App\Integrations\MercadoLibre\Connector;

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
use App\Integrations\Support\LoggedHttpClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class MercadoLibreConnector implements ConnectorInterface
{
    public function __construct(
        private readonly LoggedHttpClient $http,
    ) {}

    public function authorize(array $input = []): AuthResult
    {
        $clientId = config('connectors.mercadolibre.client_id');
        $redirectUri = $input['redirect_uri'] ?? config('connectors.mercadolibre.redirect_uri');
        $state = $input['state'] ?? Str::random(40);
        $authBase = rtrim((string) config('connectors.mercadolibre.auth_base_url'), '/');

        $url = $authBase.'/authorization?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);

        return new AuthResult([
            'authorization_url' => $url,
            'state' => $state,
        ]);
    }

    public function refreshToken(array $credentials = []): TokenResult
    {
        $url = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/').'/oauth/token';
        $body = [
            'grant_type' => 'refresh_token',
            'client_id' => config('connectors.mercadolibre.client_id'),
            'client_secret' => config('connectors.mercadolibre.client_secret'),
            'refresh_token' => $credentials['refresh_token'] ?? null,
        ];

        $response = $this->http->postForm($url, $body);

        if ($response->failed()) {
            throw new RuntimeException('MercadoLibre token refresh failed: '.$response->body());
        }

        return new TokenResult($response->json() ?? []);
    }

    public function exchangeCode(string $code): TokenResult
    {
        $url = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/').'/oauth/token';
        $body = [
            'grant_type' => 'authorization_code',
            'client_id' => config('connectors.mercadolibre.client_id'),
            'client_secret' => config('connectors.mercadolibre.client_secret'),
            'code' => $code,
            'redirect_uri' => config('connectors.mercadolibre.redirect_uri'),
        ];

        $response = $this->http->postForm($url, $body);

        if ($response->failed()) {
            throw new RuntimeException('MercadoLibre code exchange failed: '.$response->body());
        }

        return new TokenResult($response->json() ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchUserMe(string $accessToken): array
    {
        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $response = $this->http->get($base.'/users/me', [], $accessToken);

        if ($response->failed()) {
            throw new RuntimeException(
                'MercadoLibre /users/me failed: '.$response->status().' '.$response->body()
            );
        }

        $payload = $response->json() ?? [];

        if (! is_array($payload)) {
            throw new RuntimeException('MercadoLibre /users/me returned an invalid payload.');
        }

        return $payload;
    }

    /**
     * Official store brands for a seller (may be empty).
     *
     * @return list<array<string, mixed>>
     */
    public function fetchUserBrands(string $userId, string $accessToken): array
    {
        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $response = $this->http->get($base.'/users/'.$userId.'/brands', [], $accessToken);

        if ($response->status() === 404) {
            return [];
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'MercadoLibre /users/{id}/brands failed: '.$response->status().' '.$response->body()
            );
        }

        $payload = $response->json() ?? [];

        if (is_array($payload) && array_is_list($payload)) {
            /** @var list<array<string, mixed>> $payload */
            return array_values(array_filter($payload, 'is_array'));
        }

        if (is_array($payload) && isset($payload['brands']) && is_array($payload['brands'])) {
            return array_values(array_filter($payload['brands'], 'is_array'));
        }

        return [];
    }

    public function revoke(array $credentials = []): void
    {
        // Mercado Libre does not expose a standard revoke endpoint for all apps.
    }

    public function capabilities(): CapabilityMatrix
    {
        return CapabilityMatrix::make([
            'orders' => true,
            'orders_v2' => true,
            'items' => true,
            'listings' => true,
            'inventory' => true,
            'ads' => true,
            'webhooks' => true,
            'push' => true,
            'reconcile' => true,
        ]);
    }

    public function bootstrap(array $context = []): BootstrapResult
    {
        return new BootstrapResult([
            'capabilities' => $this->capabilities()->capabilities,
        ]);
    }

    public function pull(PullRequest $request): PullResult
    {
        if (in_array($request->resource, ['listings', 'items'], true)) {
            return $this->pullListings($request);
        }

        if (in_array($request->resource, ['orders', 'order'], true)) {
            return $this->pullOrders($request);
        }

        if (in_array($request->resource, ['questions', 'question'], true)) {
            return $this->pullQuestions($request);
        }

        if (in_array($request->resource, ['claims', 'claim'], true)) {
            return $this->pullClaims($request);
        }

        return new PullResult(
            items: [],
            nextCursor: ['done' => true, 'offset' => 0],
        );
    }

    private function pullListings(PullRequest $request): PullResult
    {
        $token = $request->options['access_token'] ?? null;
        $userId = $request->options['user_id'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('access_token is required to pull MercadoLibre listings.');
        }

        if (! is_string($userId) && ! is_int($userId)) {
            throw new RuntimeException('user_id is required to pull MercadoLibre listings.');
        }

        $userId = (string) $userId;
        $offset = (int) ($request->cursor['offset'] ?? 0);
        $limit = (int) ($request->options['limit'] ?? 50);
        $limit = max(1, min($limit, 50));

        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');

        $statusOption = $request->options['statuses'] ?? $request->options['status'] ?? 'active';
        $status = is_array($statusOption)
            ? (string) ($statusOption[0] ?? 'active')
            : (string) $statusOption;

        $query = [
            'status' => $status !== '' ? $status : 'active',
            'offset' => $offset,
            'limit' => $limit,
        ];

        $search = $this->http->get($base.'/users/'.$userId.'/items/search', $query, $token);

        if ($search->failed()) {
            throw new RuntimeException(
                'MercadoLibre listings search failed: '.$search->status().' '.$search->body()
            );
        }

        $payload = $search->json() ?? [];
        $ids = array_values(array_filter(
            $payload['results'] ?? [],
            fn ($id) => is_string($id) || is_int($id),
        ));

        $include = is_array($request->options['include'] ?? null)
            ? $request->options['include']
            : null;

        $items = $this->fetchItemsByIds(
            $token,
            $base,
            array_map('strval', $ids),
            $include,
        );

        $paging = is_array($payload['paging'] ?? null) ? $payload['paging'] : [];
        $total = (int) ($paging['total'] ?? ($offset + count($ids)));
        $nextOffset = $offset + count($ids);
        $done = $ids === [] || $nextOffset >= $total;

        return new PullResult(
            items: $items,
            nextCursor: [
                'offset' => $done ? $offset : $nextOffset,
                'done' => $done,
            ],
        );
    }

    private function pullOrders(PullRequest $request): PullResult
    {
        $token = $request->options['access_token'] ?? null;
        $userId = $request->options['user_id'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('access_token is required to pull MercadoLibre orders.');
        }

        if (! is_string($userId) && ! is_int($userId)) {
            throw new RuntimeException('user_id is required to pull MercadoLibre orders.');
        }

        $userId = (string) $userId;
        $offset = (int) ($request->cursor['offset'] ?? 0);
        $limit = (int) ($request->options['limit'] ?? 25);
        $limit = max(1, min($limit, 50));

        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');

        [$dateFrom, $dateTo] = $this->resolveOrderDateRange($request);

        $query = [
            'seller' => $userId,
            'order.date_created.from' => $dateFrom,
            'order.date_created.to' => $dateTo,
            'offset' => $offset,
            'limit' => $limit,
            'sort' => 'date_desc',
        ];

        // Orders search payloads are large; default 30s often times out mid-body.
        $search = $this->http->get($base.'/orders/search', $query, $token, timeout: 90);

        if ($search->failed()) {
            throw new RuntimeException(
                'MercadoLibre orders search failed: '.$search->status().' '.$search->body()
            );
        }

        $payload = $search->json() ?? [];
        $results = is_array($payload['results'] ?? null) ? $payload['results'] : [];
        $items = [];
        foreach ($results as $row) {
            if (is_array($row) && isset($row['id'])) {
                $items[] = $row;
            }
        }

        $paging = is_array($payload['paging'] ?? null) ? $payload['paging'] : [];
        $total = (int) ($paging['total'] ?? ($offset + count($items)));
        $nextOffset = $offset + count($items);
        $done = $items === [] || $nextOffset >= $total;

        return new PullResult(
            items: $items,
            nextCursor: [
                'offset' => $done ? $offset : $nextOffset,
                'done' => $done,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        );
    }

    private function pullQuestions(PullRequest $request): PullResult
    {
        $token = $request->options['access_token'] ?? null;
        $userId = $request->options['user_id'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('access_token is required to pull MercadoLibre questions.');
        }

        if (! is_string($userId) && ! is_int($userId)) {
            throw new RuntimeException('user_id is required to pull MercadoLibre questions.');
        }

        $userId = (string) $userId;
        $offset = (int) ($request->cursor['offset'] ?? 0);
        $limit = (int) ($request->options['limit'] ?? 50);
        $limit = max(1, min($limit, 50));

        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');

        $query = [
            'seller_id' => $userId,
            'api_version' => 4,
            'offset' => $offset,
            'limit' => $limit,
            'sort' => 'date_created_desc',
        ];

        $status = $request->options['status'] ?? $request->cursor['status'] ?? null;
        if (is_string($status) && $status !== '' && $status !== 'all') {
            $query['status'] = $status;
        }

        $search = $this->http->get($base.'/questions/search', $query, $token, timeout: 60);

        if ($search->failed()) {
            throw new RuntimeException(
                'MercadoLibre questions search failed: '.$search->status().' '.$search->body()
            );
        }

        $payload = $search->json() ?? [];
        $results = is_array($payload['questions'] ?? null)
            ? $payload['questions']
            : (is_array($payload['results'] ?? null) ? $payload['results'] : []);

        $items = [];
        foreach ($results as $row) {
            if (is_array($row) && isset($row['id'])) {
                $items[] = $row;
            }
        }

        $total = (int) ($payload['total'] ?? ($payload['paging']['total'] ?? ($offset + count($items))));
        $nextOffset = $offset + count($items);
        $done = $items === [] || $nextOffset >= $total;

        return new PullResult(
            items: $items,
            nextCursor: [
                'offset' => $done ? $offset : $nextOffset,
                'done' => $done,
                'status' => $status,
            ],
        );
    }

    private function pullClaims(PullRequest $request): PullResult
    {
        $token = $request->options['access_token'] ?? null;
        $userId = $request->options['user_id'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('access_token is required to pull MercadoLibre claims.');
        }

        if (! is_string($userId) && ! is_int($userId)) {
            throw new RuntimeException('user_id is required to pull MercadoLibre claims.');
        }

        $userId = (string) $userId;
        $offset = (int) ($request->cursor['offset'] ?? 0);
        $limit = (int) ($request->options['limit'] ?? 30);
        $limit = max(1, min($limit, 30));

        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');

        // ML exige un filtro "simple" (status/stage/type/…). players.* solos dan 400.
        // Bootstrap recorre opened → closed vía cursor.status.
        $explicitStatus = $request->options['status'] ?? null;
        $statusPhases = ['opened', 'closed'];
        if (is_string($explicitStatus) && $explicitStatus !== '' && $explicitStatus !== 'all') {
            $statusPhases = [$explicitStatus];
        }

        $status = $request->cursor['status'] ?? $statusPhases[0];
        if (! is_string($status) || ! in_array($status, $statusPhases, true)) {
            $status = $statusPhases[0];
        }

        $query = [
            'status' => $status,
            'players.user_id' => $userId,
            'players.role' => 'respondent',
            'offset' => $offset,
            'limit' => $limit,
            'sort' => 'date_created:desc',
        ];

        $stage = $request->options['stage'] ?? $request->cursor['stage'] ?? null;
        if (is_string($stage) && $stage !== '' && $stage !== 'all') {
            $query['stage'] = $stage;
        }

        $search = $this->http->get($base.'/post-purchase/v1/claims/search', $query, $token, timeout: 60);

        if ($search->failed()) {
            throw new RuntimeException(
                'MercadoLibre claims search failed: '.$search->status().' '.$search->body()
            );
        }

        $payload = $search->json() ?? [];
        $results = is_array($payload['data'] ?? null)
            ? $payload['data']
            : (is_array($payload['results'] ?? null) ? $payload['results'] : []);

        $items = [];
        foreach ($results as $row) {
            if (is_array($row) && isset($row['id'])) {
                $items[] = $row;
            }
        }

        $paging = is_array($payload['paging'] ?? null) ? $payload['paging'] : [];
        $total = (int) ($paging['total'] ?? ($payload['total'] ?? ($offset + count($items))));
        $nextOffset = $offset + count($items);
        $phaseDone = $items === [] || $nextOffset >= $total;

        $phaseIndex = array_search($status, $statusPhases, true);
        $hasNextPhase = is_int($phaseIndex) && isset($statusPhases[$phaseIndex + 1]);

        if ($phaseDone && $hasNextPhase) {
            return new PullResult(
                items: $items,
                nextCursor: [
                    'offset' => 0,
                    'done' => false,
                    'status' => $statusPhases[$phaseIndex + 1],
                    'stage' => $stage,
                ],
            );
        }

        return new PullResult(
            items: $items,
            nextCursor: [
                'offset' => $phaseDone ? $offset : $nextOffset,
                'done' => $phaseDone,
                'status' => $status,
                'stage' => $stage,
            ],
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveOrderDateRange(PullRequest $request): array
    {
        $dateFrom = $request->options['date_from']
            ?? $request->cursor['date_from']
            ?? null;
        $dateTo = $request->options['date_to']
            ?? $request->cursor['date_to']
            ?? null;

        if (is_string($dateFrom) && $dateFrom !== '' && is_string($dateTo) && $dateTo !== '') {
            return [$dateFrom, $dateTo];
        }

        $lookbackDays = (int) ($request->options['lookback_days'] ?? 90);
        $lookbackDays = max(1, min($lookbackDays, 3650));

        $to = now()->utc()->startOfDay()->addDay();
        $from = $to->copy()->subDays($lookbackDays);

        return [
            $from->format('Y-m-d\TH:i:s.000\Z'),
            $to->format('Y-m-d\TH:i:s.000\Z'),
        ];
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, mixed>|null  $include
     * @return list<array<string, mixed>>
     */
    private function fetchItemsByIds(string $token, string $base, array $ids, ?array $include = null): array
    {
        if ($ids === []) {
            return [];
        }

        $items = [];
        $attributes = $this->resolveMultigetAttributes($include);

        foreach (array_chunk($ids, 20) as $chunk) {
            $query = ['ids' => implode(',', $chunk)];
            if ($attributes !== null) {
                $query['attributes'] = $attributes;
            }

            $response = $this->http->get($base.'/items', $query, $token);

            if ($response->failed()) {
                throw new RuntimeException(
                    'MercadoLibre items multiget failed: '.$response->status().' '.$response->body()
                );
            }

            foreach ($response->json() ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $code = (int) ($row['code'] ?? 0);
                $body = $row['body'] ?? null;

                if ($code === 200 && is_array($body)) {
                    $items[] = $body;
                }
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>|null  $include
     */
    private function resolveMultigetAttributes(?array $include): ?string
    {
        if ($include === null) {
            return null;
        }

        $attrs = [
            'id',
            'title',
            'status',
            'permalink',
            'currency_id',
            'seller_custom_field',
            'seller_sku',
            'variations',
            'last_updated',
            'user_product_id',
            'inventory_id',
            'shipping',
        ];

        if (! empty($include['price'])) {
            $attrs[] = 'price';
        }
        if (! empty($include['stock'])) {
            $attrs[] = 'available_quantity';
        }
        if (! empty($include['pictures'])) {
            $attrs[] = 'pictures';
        }
        if (! empty($include['attributes'])) {
            $attrs[] = 'attributes';
        }

        return implode(',', array_values(array_unique($attrs)));
    }

    public function fetch(FetchRequest $request): FetchResult
    {
        $token = $request->options['access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('access_token is required to fetch MercadoLibre resources.');
        }

        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $resource = $request->resource;

        $path = match (true) {
            in_array($resource, ['order', 'orders'], true) => '/orders/'.$request->externalId,
            in_array($resource, ['shipment', 'shipments'], true) => '/shipments/'.$request->externalId,
            in_array($resource, ['question', 'questions'], true) => '/questions/'.$request->externalId,
            in_array($resource, ['claim', 'claims'], true) => '/post-purchase/v1/claims/'.$request->externalId,
            in_array($resource, ['payment', 'payments', 'collection', 'collections'], true) => '/collections/'.$request->externalId,
            in_array($resource, ['item_description', 'description'], true) => '/items/'.$request->externalId.'/description',
            in_array($resource, ['item', 'items', 'listing'], true) => '/items/'.$request->externalId,
            default => '/'.$resource.'/'.$request->externalId,
        };

        $query = in_array($resource, ['question', 'questions'], true)
            ? ['api_version' => 4]
            : [];

        $response = $this->http->get($base.$path, $query, $token);

        if ($response->failed()) {
            // Missing description is common; treat as empty payload instead of failing the sync.
            if (
                in_array($resource, ['item_description', 'description'], true)
                && $response->status() === 404
            ) {
                return new FetchResult([]);
            }

            throw new RuntimeException('MercadoLibre fetch failed: '.$response->status().' '.$response->body());
        }

        return new FetchResult($response->json() ?? []);
    }

    /**
     * @return array{messages: list<array<string, mixed>>, conversation_status: array<string, mixed>|null, paging: array<string, mixed>|null, seller_max_message_length: int|null}
     */
    public function fetchPackMessages(
        string $packId,
        string $sellerId,
        string $accessToken,
        bool $markAsRead = false,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $query = [
            'tag' => 'post_sale',
            'mark_as_read' => $markAsRead ? 'true' : 'false',
            'limit' => max(1, min($limit, 50)),
            'offset' => max(0, $offset),
        ];

        $response = $this->http->get(
            $base.'/messages/packs/'.$packId.'/sellers/'.$sellerId,
            $query,
            $accessToken,
            timeout: 60,
        );

        if ($response->failed()) {
            throw new RuntimeException(
                'MercadoLibre pack messages failed: '.$response->status().' '.$response->body()
            );
        }

        $payload = $response->json() ?? [];
        $messages = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];

        return [
            'messages' => array_values(array_filter($messages, 'is_array')),
            'conversation_status' => is_array($payload['conversation_status'] ?? null)
                ? $payload['conversation_status']
                : null,
            'paging' => is_array($payload['paging'] ?? null) ? $payload['paging'] : null,
            'seller_max_message_length' => isset($payload['seller_max_message_length'])
                ? (int) $payload['seller_max_message_length']
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function sendPackMessage(
        string $packId,
        string $sellerId,
        string $accessToken,
        array $body,
        bool $dryRun = false,
    ): array {
        if ($dryRun) {
            return [
                'ok' => true,
                'dry_run' => true,
                'pack_id' => $packId,
                'body' => $body,
            ];
        }

        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $response = $this->http->postJson(
            $base.'/messages/packs/'.$packId.'/sellers/'.$sellerId.'?tag=post_sale',
            $body,
            $accessToken,
        );

        if ($response->failed()) {
            throw new RuntimeException(
                'MercadoLibre send pack message failed: '.$response->status().' '.$response->body()
            );
        }

        return [
            'ok' => true,
            'dry_run' => false,
            'response' => $response->json() ?? [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchClaimMessages(string $claimId, string $accessToken): array
    {
        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $response = $this->http->get(
            $base.'/post-purchase/v1/claims/'.$claimId.'/messages',
            [],
            $accessToken,
            timeout: 60,
        );

        if ($response->failed()) {
            throw new RuntimeException(
                'MercadoLibre claim messages failed: '.$response->status().' '.$response->body()
            );
        }

        $payload = $response->json() ?? [];
        if (! is_array($payload)) {
            return [];
        }

        // API may return a bare list or { messages: [...] }.
        $rows = array_is_list($payload)
            ? $payload
            : (is_array($payload['messages'] ?? null) ? $payload['messages'] : []);

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @return array{body: string, content_type: string, status: int}
     */
    public function downloadClaimAttachment(string $claimId, string $filename, string $accessToken): array
    {
        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $encoded = rawurlencode($filename);
        $response = $this->http->getBinary(
            $base.'/post-purchase/v1/claims/'.$claimId.'/attachments/'.$encoded.'/download',
            [],
            $accessToken,
            timeout: 90,
        );

        if ($response->failed()) {
            throw new RuntimeException(
                'MercadoLibre claim attachment download failed: '.$response->status().' '.$response->body()
            );
        }

        $contentType = (string) ($response->header('Content-Type') ?: 'application/octet-stream');
        if (str_contains($contentType, ';')) {
            $contentType = trim(explode(';', $contentType, 2)[0]);
        }

        return [
            'body' => $response->body(),
            'content_type' => $contentType !== '' ? $contentType : 'application/octet-stream',
            'status' => $response->status(),
        ];
    }

    /**
     * Facturador SAT: invoices emitted for a seller order.
     *
     * @return array{ok: bool, status: int, json: array<string, mixed>|null, body: string, content_type: string}
     */
    public function listSellerInvoices(string $sellerId, string $orderId, string $accessToken): array
    {
        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $byOrder = $this->wrapMlResponse(
            $this->http->get(
                $base.'/users/'.$sellerId.'/invoices/orders/'.$orderId,
                [],
                $accessToken,
                timeout: 60,
            ),
            expectJson: true,
        );

        if ($byOrder['status'] !== 405) {
            return $byOrder;
        }

        return $this->wrapMlResponse(
            $this->http->get(
                $base.'/users/'.$sellerId.'/invoices',
                ['order_id' => $orderId],
                $accessToken,
                timeout: 60,
            ),
            expectJson: true,
        );
    }

    /**
     * @return array{ok: bool, status: int, json: array<string, mixed>|null, body: string, content_type: string}
     */
    public function fetchSellerInvoice(string $sellerId, string $invoiceId, string $accessToken): array
    {
        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');

        return $this->wrapMlResponse(
            $this->http->get(
                $base.'/users/'.$sellerId.'/invoices/'.$invoiceId,
                [],
                $accessToken,
                timeout: 90,
            ),
            expectJson: true,
        );
    }

    /**
     * Fiscal documents attached to a pack (PDF/XML uploaded or issued).
     *
     * @return array{ok: bool, status: int, json: array<string, mixed>|null, body: string, content_type: string}
     */
    public function listPackFiscalDocuments(string $packId, string $accessToken): array
    {
        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');

        return $this->wrapMlResponse(
            $this->http->get(
                $base.'/packs/'.$packId.'/fiscal_documents',
                [],
                $accessToken,
                timeout: 60,
            ),
            expectJson: true,
        );
    }

    /**
     * @return array{ok: bool, status: int, json: array<string, mixed>|null, body: string, content_type: string}
     */
    public function downloadPackFiscalDocument(string $packId, string $documentId, string $accessToken): array
    {
        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');

        return $this->wrapMlResponse(
            $this->http->getBinary(
                $base.'/packs/'.$packId.'/fiscal_documents/'.rawurlencode($documentId),
                [],
                $accessToken,
                timeout: 90,
            ),
            expectJson: false,
        );
    }

    /**
     * @return array{ok: bool, status: int, json: array<string, mixed>|null, body: string, content_type: string}
     */
    private function wrapMlResponse(Response $response, bool $expectJson): array
    {
        $contentType = (string) ($response->header('Content-Type') ?: '');
        if (str_contains($contentType, ';')) {
            $contentType = trim(explode(';', $contentType, 2)[0]);
        }

        $json = null;
        if ($expectJson) {
            $decoded = $response->json();
            $json = is_array($decoded) ? $decoded : null;
        }

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'json' => $json,
            'body' => $response->body(),
            'content_type' => $contentType !== ''
                ? $contentType
                : ($expectJson ? 'application/json' : 'application/octet-stream'),
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function sendClaimMessage(
        string $claimId,
        string $accessToken,
        array $body,
        bool $dryRun = false,
    ): array {
        if ($dryRun) {
            return [
                'ok' => true,
                'dry_run' => true,
                'claim_id' => $claimId,
                'body' => $body,
            ];
        }

        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $response = $this->http->postJson(
            $base.'/post-purchase/v1/claims/'.$claimId.'/messages',
            $body,
            $accessToken,
        );

        if ($response->failed()) {
            throw new RuntimeException(
                'MercadoLibre send claim message failed: '.$response->status().' '.$response->body()
            );
        }

        return [
            'ok' => true,
            'dry_run' => false,
            'response' => $response->json() ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchClaimReason(string $reasonId, string $accessToken): array
    {
        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $paths = [
            '/post-purchase/v1/claims/reasons/'.$reasonId,
            '/post-purchase/v1/reasons/'.$reasonId,
        ];

        $lastStatus = 0;
        $lastBody = '';
        foreach ($paths as $path) {
            $response = $this->http->get($base.$path, [], $accessToken, timeout: 30);
            if ($response->successful()) {
                $payload = $response->json() ?? [];

                return is_array($payload) ? $payload : [];
            }
            $lastStatus = $response->status();
            $lastBody = $response->body();
            if ($response->status() !== 404) {
                break;
            }
        }

        throw new RuntimeException(
            'MercadoLibre claim reason failed: '.$lastStatus.' '.$lastBody
        );
    }

    /**
     * Seller-facing claim status card (problem text, due date, title).
     *
     * @return array<string, mixed>
     */
    public function fetchClaimDetail(string $claimId, string $accessToken): array
    {
        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $response = $this->http->get(
            $base.'/post-purchase/v1/claims/'.$claimId.'/detail',
            [],
            $accessToken,
            timeout: 30,
        );

        if ($response->failed()) {
            throw new RuntimeException(
                'MercadoLibre claim detail failed: '.$response->status().' '.$response->body()
            );
        }

        $payload = $response->json() ?? [];

        return is_array($payload) ? $payload : [];
    }

    /**
     * @return array{affects_reputation: string|null, has_incentive: bool|null, due_date: string|null}
     */
    public function fetchAffectsReputation(string $claimId, string $accessToken): array
    {
        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $response = $this->http->get(
            $base.'/post-purchase/v1/claims/'.$claimId.'/affects-reputation',
            [],
            $accessToken,
            timeout: 30,
        );

        if ($response->failed()) {
            throw new RuntimeException(
                'MercadoLibre affects-reputation failed: '.$response->status().' '.$response->body()
            );
        }

        $payload = $response->json() ?? [];
        $value = is_array($payload) ? ($payload['affects_reputation'] ?? null) : null;
        $hasIncentive = is_array($payload) ? ($payload['has_incentive'] ?? null) : null;
        $dueDate = is_array($payload) ? ($payload['due_date'] ?? null) : null;

        return [
            'affects_reputation' => is_string($value) ? $value : null,
            'has_incentive' => is_bool($hasIncentive) ? $hasIncentive : null,
            'due_date' => is_string($dueDate) && $dueDate !== '' ? $dueDate : null,
        ];
    }

    public function siteIdToPurchaseExperienceLocale(?string $siteId): string
    {
        $map = config('mercadolibre_reputation.purchase_experience_locales', []);
        $site = strtoupper(trim((string) $siteId));
        if (is_array($map) && isset($map[$site]) && is_string($map[$site])) {
            return $map[$site];
        }

        return (string) config('mercadolibre_reputation.purchase_experience_fallback_locale', 'es_MX');
    }

    /**
     * @return array{payload: array<string, mixed>, source: string, external_id: string}
     */
    public function fetchPurchaseExperience(
        string $itemId,
        string $accessToken,
        ?string $locale = null,
        ?string $userProductId = null,
    ): array {
        $locale = $locale ?: $this->siteIdToPurchaseExperienceLocale(null);
        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $itemUrl = $base.'/reputation/items/'.$itemId.'/purchase_experience/integrators';

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(30)
            ->withoutRedirecting()
            ->get($itemUrl, ['locale' => $locale]);

        if ($response->status() === 302 || $response->status() === 301) {
            $location = (string) $response->header('Location');
            $upFromLocation = null;
            if (preg_match('#/reputation/user_products/([^/]+)/purchase_experience#', $location, $m)) {
                $upFromLocation = $m[1];
            }
            $upId = $upFromLocation ?: $userProductId;
            if ($upId === null || $upId === '') {
                throw new RuntimeException(
                    'MercadoLibre purchase_experience redirected to user_product but no UP id was available.'
                );
            }

            return $this->fetchPurchaseExperienceByUserProduct($upId, $accessToken, $locale);
        }

        if ($response->failed()) {
            // Fallback: some accounts already require UP even without redirect.
            if ($userProductId !== null && $userProductId !== '' && in_array($response->status(), [404, 400], true)) {
                return $this->fetchPurchaseExperienceByUserProduct($userProductId, $accessToken, $locale);
            }

            throw new RuntimeException(
                'MercadoLibre purchase_experience item failed: '.$response->status().' '.$response->body()
            );
        }

        $payload = $response->json() ?? [];
        if (! is_array($payload)) {
            throw new RuntimeException('MercadoLibre purchase_experience returned an invalid payload.');
        }

        return [
            'payload' => $payload,
            'source' => 'item',
            'external_id' => $itemId,
        ];
    }

    /**
     * @return array{payload: array<string, mixed>, source: string, external_id: string}
     */
    public function fetchPurchaseExperienceByUserProduct(
        string $userProductId,
        string $accessToken,
        ?string $locale = null,
    ): array {
        $locale = $locale ?: $this->siteIdToPurchaseExperienceLocale(null);
        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $url = $base.'/reputation/user_products/'.$userProductId.'/purchase_experience/integrators';

        $response = $this->http->get($url, ['locale' => $locale], $accessToken, timeout: 30);

        if ($response->failed()) {
            throw new RuntimeException(
                'MercadoLibre purchase_experience user_product failed: '.$response->status().' '.$response->body()
            );
        }

        $payload = $response->json() ?? [];
        if (! is_array($payload)) {
            throw new RuntimeException('MercadoLibre purchase_experience UP returned an invalid payload.');
        }

        return [
            'payload' => $payload,
            'source' => 'user_product',
            'external_id' => $userProductId,
        ];
    }

    public function parseWebhook(WebhookPayload $payload): ParsedWebhook
    {
        $body = is_array($payload->body) ? $payload->body : (json_decode((string) $payload->body, true) ?: []);

        $topic = (string) ($body['topic'] ?? $body['_topic'] ?? 'orders');
        $resource = (string) ($body['resource'] ?? '');
        $userId = isset($body['user_id']) ? (string) $body['user_id'] : null;

        $externalId = null;
        if (preg_match('#/(?:orders|items|shipments|questions|claims|payments|collections)/([^/?]+)#', $resource, $m)) {
            $externalId = $m[1];
        } elseif (isset($body['id'])) {
            $externalId = (string) $body['id'];
        }

        $resourceType = match (true) {
            str_contains($topic, 'claim') || str_contains($resource, '/claims/') => 'claim',
            str_contains($topic, 'question') || str_contains($resource, '/questions/') => 'question',
            str_contains($topic, 'shipment') || str_contains($resource, '/shipments/') => 'shipment',
            str_contains($topic, 'payment') || str_contains($resource, '/payments/') || str_contains($resource, '/collections/') => 'payment',
            str_contains($topic, 'order') || str_contains($resource, '/orders/') => 'order',
            str_contains($topic, 'item') || str_contains($resource, '/items/') => 'item',
            default => $topic,
        };

        $events = [];
        if ($externalId !== null) {
            $events[] = [
                'resource_type' => $resourceType,
                'external_id' => $externalId,
                'user_id' => $userId,
                'topic' => $topic,
                'resource' => $resource,
            ];
        }

        return new ParsedWebhook(type: $resourceType, events: $events);
    }

    public function push(PushRequest $request): PushResult
    {
        $payload = $request->payload;
        $dryRun = (bool) ($payload['dry_run'] ?? true);

        if (in_array($request->resource, ['question_answer', 'answer'], true)) {
            return $this->pushQuestionAnswer($payload, $dryRun);
        }

        $itemId = (string) ($payload['external_item_id'] ?? $payload['item_id'] ?? '');

        if ($itemId === '') {
            throw new RuntimeException('external_item_id is required for MercadoLibre push.');
        }

        $body = match ($request->resource) {
            'item_stock', 'stock_update' => $this->stockUpdateBody($payload),
            'user_product_stock', 'seller_warehouse_stock' => null,
            'item_price', 'price_update' => [
                'price' => (float) ($payload['price'] ?? 0),
            ],
            'item_status', 'listing_status' => [
                'status' => (string) ($payload['status'] ?? 'active'),
            ],
            'item_update', 'listing_update' => array_filter([
                'title' => $payload['title'] ?? null,
                'price' => isset($payload['price']) ? (float) $payload['price'] : null,
                'available_quantity' => isset($payload['available_quantity'])
                    ? (int) $payload['available_quantity']
                    : null,
                'status' => $payload['status'] ?? null,
            ], fn ($v) => $v !== null),
            default => array_filter([
                'available_quantity' => $payload['available_quantity'] ?? null,
                'price' => $payload['price'] ?? null,
                'status' => $payload['status'] ?? null,
                'title' => $payload['title'] ?? null,
            ], fn ($v) => $v !== null),
        };

        if (in_array($request->resource, ['user_product_stock', 'seller_warehouse_stock'], true)
            || (! empty($payload['stock_type']) && $payload['stock_type'] === 'seller_warehouse')
        ) {
            return $this->pushSellerWarehouseStock($payload, $dryRun);
        }

        if ($body === null) {
            throw new RuntimeException('Unsupported MercadoLibre push resource.');
        }

        if ($dryRun) {
            return new PushResult([
                'ok' => true,
                'dry_run' => true,
                'resource' => $request->resource,
                'item_id' => $itemId,
                'body' => $body,
            ]);
        }

        $token = $payload['access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('access_token is required for MercadoLibre push.');
        }

        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $response = $this->http->putJson($base.'/items/'.$itemId, $body, $token);

        if ($response->failed()) {
            throw new RuntimeException('MercadoLibre push failed: '.$response->status().' '.$response->body());
        }

        return new PushResult([
            'ok' => true,
            'dry_run' => false,
            'resource' => $request->resource,
            'item_id' => $itemId,
            'response' => $response->json() ?? [],
        ]);
    }

    public function reconcile(ReconcileRequest $request): ReconcileResult
    {
        return new ReconcileResult(['stub' => true, 'resource' => $request->resource]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stockUpdateBody(array $payload): array
    {
        $qty = (int) ($payload['available_quantity'] ?? $payload['quantity'] ?? 0);
        $variationId = $payload['external_variation_id'] ?? null;

        if ($variationId !== null && $variationId !== '' && $variationId !== '0') {
            return [
                'variations' => [[
                    'id' => is_numeric($variationId) ? (int) $variationId : $variationId,
                    'available_quantity' => $qty,
                ]],
            ];
        }

        return ['available_quantity' => $qty];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pushQuestionAnswer(array $payload, bool $dryRun): PushResult
    {
        $questionId = $payload['question_id'] ?? $payload['external_question_id'] ?? null;
        $text = isset($payload['text']) ? trim((string) $payload['text']) : '';

        if ($questionId === null || $questionId === '') {
            throw new RuntimeException('question_id is required for MercadoLibre question answer.');
        }

        if ($text === '') {
            throw new RuntimeException('text is required for MercadoLibre question answer.');
        }

        $body = [
            'question_id' => is_numeric($questionId) ? (int) $questionId : $questionId,
            'text' => $text,
        ];

        if ($dryRun) {
            return new PushResult([
                'ok' => true,
                'dry_run' => true,
                'resource' => 'question_answer',
                'question_id' => (string) $questionId,
                'body' => $body,
            ]);
        }

        $token = $payload['access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('access_token is required for MercadoLibre question answer.');
        }

        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $response = $this->http->postJson($base.'/answers', $body, $token);

        if ($response->failed()) {
            throw new RuntimeException(
                'MercadoLibre question answer failed: '.$response->status().' '.$response->body()
            );
        }

        return new PushResult([
            'ok' => true,
            'dry_run' => false,
            'resource' => 'question_answer',
            'question_id' => (string) $questionId,
            'response' => $response->json() ?? [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pushSellerWarehouseStock(array $payload, bool $dryRun): PushResult
    {
        $userProductId = (string) ($payload['user_product_id'] ?? '');
        if ($userProductId === '') {
            throw new RuntimeException('user_product_id is required for seller_warehouse stock push.');
        }

        $locations = $payload['locations'] ?? [[
            'store_id' => $payload['store_id'] ?? null,
            'network_node_id' => $payload['network_node_id'] ?? null,
            'quantity' => (int) ($payload['available_quantity'] ?? $payload['quantity'] ?? 0),
        ]];

        $body = ['locations' => array_values(array_map(static function ($loc) {
            return array_filter([
                'store_id' => $loc['store_id'] ?? null,
                'network_node_id' => $loc['network_node_id'] ?? null,
                'quantity' => (int) ($loc['quantity'] ?? 0),
            ], static fn ($v) => $v !== null && $v !== '');
        }, is_array($locations) ? $locations : []))];

        if ($dryRun) {
            return new PushResult([
                'ok' => true,
                'dry_run' => true,
                'resource' => 'seller_warehouse_stock',
                'user_product_id' => $userProductId,
                'body' => $body,
            ]);
        }

        $token = $payload['access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('access_token is required for MercadoLibre push.');
        }

        $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
        $version = $payload['stock_version'] ?? null;

        if ($version === null || $version === '') {
            $get = $this->http->get($base.'/user-products/'.$userProductId.'/stock', [], $token);
            $version = $get->header('x-version') ?? $get->header('X-Version');
        }

        $headers = ['Content-Type' => 'application/json'];
        if (is_string($version) && $version !== '') {
            $headers['x-version'] = $version;
        }

        $response = $this->http->putJson(
            $base.'/user-products/'.$userProductId.'/stock/type/seller_warehouse',
            $body,
            $token,
            $headers,
        );

        if ($response->failed()) {
            throw new RuntimeException('MercadoLibre seller_warehouse push failed: '.$response->status().' '.$response->body());
        }

        return new PushResult([
            'ok' => true,
            'dry_run' => false,
            'resource' => 'seller_warehouse_stock',
            'user_product_id' => $userProductId,
            'response' => $response->json() ?? [],
        ]);
    }

    public function classifyError(Throwable $e): ClassifiedError
    {
        if ($e instanceof RequestException) {
            $status = $e->response?->status();

            return new ClassifiedError(
                category: match (true) {
                    $status === 401, $status === 403 => 'auth',
                    $status === 429 => 'rate_limit',
                    $status !== null && $status >= 500 => 'provider',
                    default => 'client',
                },
                retryable: in_array($status, [429, 500, 502, 503, 504], true),
                message: $e->getMessage(),
                context: ['status' => $status],
            );
        }

        return new ClassifiedError(
            category: 'unknown',
            retryable: false,
            message: $e->getMessage(),
        );
    }
}
