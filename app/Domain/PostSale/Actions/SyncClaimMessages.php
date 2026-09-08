<?php

namespace App\Domain\PostSale\Actions;

use App\Integrations\Contracts\ConnectorRegistry;
use App\Models\Claim;
use App\Models\ClaimMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

final class SyncClaimMessages
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly EnrichClaimDetails $enrich,
    ) {}

    /**
     * @return array{claim: Claim, messages: Collection<int, ClaimMessage>}
     */
    public function execute(Claim $claim, bool $refreshFromProvider = true): array
    {
        $claim->loadMissing('connection');

        if ($refreshFromProvider) {
            $this->pullFromProvider($claim);
            $this->enrich->execute($claim, force: true);
            $claim->refresh();
        } elseif (
            blank($claim->reason_detail)
            || blank($claim->affects_reputation)
            || blank($claim->problem)
            || blank($claim->status_title)
        ) {
            $this->enrich->execute($claim);
            $claim->refresh();
        }

        $messages = ClaimMessage::query()
            ->where('claim_id', $claim->id)
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get();

        return [
            'claim' => $claim,
            'messages' => $messages,
        ];
    }

    private function pullFromProvider(Claim $claim): void
    {
        $connection = $claim->connection;
        if ($connection === null || $connection->provider !== 'mercadolibre') {
            throw new RuntimeException('Solo se pueden sincronizar mensajes de reclamos de Mercado Libre.');
        }

        $token = $this->accessToken($connection);
        $claimId = $claim->external_claim_id;
        if ($token === null || ! is_string($claimId) || $claimId === '') {
            throw new RuntimeException('Falta access_token o external_claim_id para mensajes del reclamo.');
        }

        /** @var \App\Integrations\MercadoLibre\Connector\MercadoLibreConnector $connector */
        $connector = $this->registry->get('mercadolibre');
        $rows = $connector->fetchClaimMessages($claimId, $token);

        foreach ($rows as $row) {
            $this->upsertMessage($claim, $row);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function upsertMessage(Claim $claim, array $row): ClaimMessage
    {
        $sender = isset($row['sender_role']) ? (string) $row['sender_role'] : '';
        $receiver = isset($row['receiver_role']) ? (string) $row['receiver_role'] : '';
        $message = isset($row['message']) ? (string) $row['message'] : '';
        $sentAt = $this->parseDate(
            $row['message_date'] ?? $row['date_created'] ?? $row['date'] ?? null
        );
        $key = hash('sha256', implode('|', [
            (string) $claim->external_claim_id,
            $sender,
            $receiver,
            $sentAt?->toIso8601String() ?? '',
            mb_substr($message, 0, 200),
        ]));

        return ClaimMessage::query()->updateOrCreate(
            [
                'claim_id' => $claim->id,
                'external_message_key' => $key,
            ],
            [
                'workspace_id' => $claim->workspace_id,
                'connection_id' => $claim->connection_id,
                'sender_role' => $sender !== '' ? $sender : null,
                'receiver_role' => $receiver !== '' ? $receiver : null,
                'stage' => isset($row['stage']) ? (string) $row['stage'] : null,
                'message' => $message !== '' ? $message : null,
                'sent_at' => $sentAt,
                'meta' => array_filter([
                    'attachments' => $row['attachments'] ?? null,
                    'moderation' => $row['moderation'] ?? null,
                    'translated_message' => $row['translated_message'] ?? null,
                    'status' => $row['status'] ?? null,
                    'date_read' => $row['date_read'] ?? null,
                    'last_updated' => $row['last_updated'] ?? null,
                    'repeated' => $row['repeated'] ?? null,
                ], static fn ($v) => $v !== null),
            ],
        );
    }

    private function accessToken(\App\Models\Connection $connection): ?string
    {
        try {
            return app(\App\Domain\Integrations\Actions\EnsureFreshConnectionToken::class)
                ->execute($connection);
        } catch (Throwable) {
            return null;
        }
    }

    private function parseDate(mixed $value): ?Carbon
    {
        return \App\Domain\Shared\Support\ProviderDateTime::parseUtc($value);
    }
}
