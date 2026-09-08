<?php

namespace App\Domain\PostSale\Actions;

use App\Integrations\Contracts\ConnectorRegistry;
use App\Models\Connection;
use App\Models\Order;
use App\Models\OrderMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

final class SyncOrderMessages
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
    ) {}

    /**
     * @return array{messages: Collection<int, OrderMessage>, conversation_status: array<string, mixed>|null, seller_max_message_length: int|null}
     */
    public function execute(Order $order, bool $refreshFromProvider = true): array
    {
        $order->loadMissing(['connection', 'pack']);

        if ($order->connection === null || $order->connection->provider !== 'mercadolibre') {
            throw new RuntimeException('Solo se pueden sincronizar mensajes de Mercado Libre.');
        }

        $conversationStatus = null;
        $maxLength = null;

        if ($refreshFromProvider) {
            [$conversationStatus, $maxLength] = $this->pullFromProvider($order);
        }

        $messages = OrderMessage::query()
            ->where('order_id', $order->id)
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get();

        return [
            'messages' => $messages,
            'conversation_status' => $conversationStatus,
            'seller_max_message_length' => $maxLength,
        ];
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: int|null}
     */
    private function pullFromProvider(Order $order): array
    {
        $connection = $order->connection;
        $token = $this->accessToken($connection);
        $sellerId = $connection->external_user_id;
        $packId = $order->messagingPackExternalId();

        if ($token === null || ! is_string($sellerId) || $sellerId === '' || $packId === null) {
            throw new RuntimeException('Falta access_token, seller_id o pack_id para mensajes.');
        }

        /** @var \App\Integrations\MercadoLibre\Connector\MercadoLibreConnector $connector */
        $connector = $this->registry->get('mercadolibre');
        $result = $connector->fetchPackMessages($packId, $sellerId, $token, markAsRead: false);

        foreach ($result['messages'] as $row) {
            $this->upsertMessage($order, $packId, $sellerId, $row);
        }

        $meta = is_array($order->meta) ? $order->meta : [];
        $meta['messaging'] = array_filter([
            'conversation_status' => $result['conversation_status'],
            'seller_max_message_length' => $result['seller_max_message_length'],
            'synced_at' => now()->toIso8601String(),
            'messaging_pack_id' => $packId,
        ], static fn ($v) => $v !== null);
        $order->forceFill(['meta' => $meta])->save();

        return [$result['conversation_status'], $result['seller_max_message_length']];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function upsertMessage(Order $order, string $packId, string $sellerId, array $row): OrderMessage
    {
        $externalId = (string) ($row['id'] ?? $row['message_id'] ?? '');
        if ($externalId === '') {
            throw new RuntimeException('Mensaje de ML sin id.');
        }

        $fromId = isset($row['from']['user_id']) ? (string) $row['from']['user_id'] : null;
        $toId = isset($row['to']['user_id']) ? (string) $row['to']['user_id'] : null;
        $text = $this->extractText($row);
        $dates = is_array($row['message_date'] ?? null) ? $row['message_date'] : [];
        $sentAt = $this->parseDate($dates['created'] ?? $dates['received'] ?? $row['date_created'] ?? $row['date'] ?? null);
        $readAt = $this->parseDate($dates['read'] ?? $row['date_read'] ?? null);

        $direction = $fromId !== null && $fromId === (string) $sellerId
            ? 'outbound'
            : 'inbound';

        return OrderMessage::query()->updateOrCreate(
            [
                'connection_id' => $order->connection_id,
                'external_message_id' => $externalId,
            ],
            [
                'workspace_id' => $order->workspace_id,
                'order_id' => $order->id,
                'pack_id' => $order->pack_id,
                'external_pack_id' => $packId,
                'direction' => $direction,
                'from_external_user_id' => $fromId,
                'to_external_user_id' => $toId,
                'text' => $text,
                'status' => (string) ($row['status'] ?? 'available'),
                'sent_at' => $sentAt,
                'read_at' => $readAt,
                'meta' => [
                    'message_moderation' => $row['message_moderation'] ?? $row['moderation'] ?? null,
                    'message_attachments' => $row['message_attachments'] ?? $row['attachments'] ?? null,
                    'message_resources' => $row['message_resources'] ?? null,
                    'conversation_first_message' => $row['conversation_first_message'] ?? null,
                ],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function extractText(array $row): ?string
    {
        if (is_string($row['text'] ?? null)) {
            return $row['text'];
        }
        if (is_array($row['text'] ?? null) && isset($row['text']['plain'])) {
            return (string) $row['text']['plain'];
        }

        return null;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        return \App\Domain\Shared\Support\ProviderDateTime::parseUtc($value);
    }

    private function accessToken(Connection $connection): ?string
    {
        try {
            return app(\App\Domain\Integrations\Actions\EnsureFreshConnectionToken::class)
                ->execute($connection);
        } catch (\Throwable) {
            return null;
        }
    }
}
