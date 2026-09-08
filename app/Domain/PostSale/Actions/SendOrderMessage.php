<?php

namespace App\Domain\PostSale\Actions;

use App\Integrations\Contracts\ConnectorRegistry;
use App\Models\Connection;
use App\Models\Order;
use App\Models\OrderMessage;
use App\Models\Workspace;
use RuntimeException;

final class SendOrderMessage
{
    /** Agent user IDs for new messaging architecture by site. */
    private const AGENT_BY_SITE = [
        'MLC' => '3020819166',
        'MCO' => '3037204123',
        'MLM' => '3037204279',
        'MLA' => '3037674934',
        'MLB' => '3037675074',
        'MLU' => '3037204685',
    ];

    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly SyncOrderMessages $sync,
    ) {}

    public function execute(Order $order, string $text): OrderMessage
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('El mensaje no puede estar vacío.');
        }

        if (mb_strlen($text) > 350) {
            throw new RuntimeException('El mensaje supera el límite de 350 caracteres.');
        }

        $order->loadMissing(['connection', 'pack']);
        $connection = $order->connection;
        if ($connection === null || $connection->provider !== 'mercadolibre') {
            throw new RuntimeException('Solo se pueden enviar mensajes de Mercado Libre.');
        }

        $token = $this->accessToken($connection);
        $sellerId = $connection->external_user_id;
        $packId = $order->messagingPackExternalId();
        $buyerId = $order->buyer_external_id;

        if ($token === null || ! is_string($sellerId) || $sellerId === '' || $packId === null) {
            throw new RuntimeException('Falta access_token, seller_id o pack_id para enviar el mensaje.');
        }

        $toUserId = $this->resolveRecipientUserId($connection, $buyerId);
        if ($toUserId === null) {
            throw new RuntimeException('No se pudo determinar el destinatario del mensaje.');
        }

        $workspace = Workspace::query()->find($order->workspace_id);
        $dryRun = (bool) ($workspace?->outbound_dry_run ?? true);

        /** @var \App\Integrations\MercadoLibre\Connector\MercadoLibreConnector $connector */
        $connector = $this->registry->get('mercadolibre');
        $connector->sendPackMessage($packId, (string) $sellerId, $token, [
            'from' => ['user_id' => (string) $sellerId],
            'to' => ['user_id' => $toUserId],
            'text' => $text,
        ], $dryRun);

        if ($dryRun) {
            return OrderMessage::query()->create([
                'workspace_id' => $order->workspace_id,
                'connection_id' => $connection->id,
                'order_id' => $order->id,
                'pack_id' => $order->pack_id,
                'external_message_id' => 'dry-run-'.uniqid('', true),
                'external_pack_id' => $packId,
                'direction' => 'outbound',
                'from_external_user_id' => (string) $sellerId,
                'to_external_user_id' => $toUserId,
                'text' => $text,
                'status' => 'available',
                'sent_at' => now(),
                'meta' => ['dry_run' => true],
            ]);
        }

        $this->sync->execute($order, refreshFromProvider: true);

        $message = OrderMessage::query()
            ->where('order_id', $order->id)
            ->where('direction', 'outbound')
            ->where('text', $text)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first();

        if ($message === null) {
            throw new RuntimeException('El mensaje se envió pero no se pudo confirmar en el espejo local.');
        }

        return $message;
    }

    private function resolveRecipientUserId(Connection $connection, ?string $buyerId): ?string
    {
        $site = strtoupper((string) ($connection->site_id ?? ''));
        // Nueva arquitectura de agentes: documentada primero para MLB/MLC.
        if (in_array($site, ['MLB', 'MLC'], true) && isset(self::AGENT_BY_SITE[$site])) {
            return self::AGENT_BY_SITE[$site];
        }

        return $buyerId !== null && $buyerId !== '' ? $buyerId : null;
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
