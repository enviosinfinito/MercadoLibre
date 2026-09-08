<?php

namespace App\Domain\PostSale\Actions;

use App\Integrations\Contracts\ConnectorRegistry;
use App\Models\Claim;
use App\Models\ClaimMessage;
use App\Models\Workspace;
use RuntimeException;
use Throwable;

final class SendClaimMessage
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly SyncClaimMessages $sync,
    ) {}

    public function execute(Claim $claim, string $text): ClaimMessage
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('El mensaje no puede estar vacío.');
        }

        if (mb_strlen($text) > 3500) {
            throw new RuntimeException('El mensaje supera el límite permitido.');
        }

        $claim->loadMissing('connection');
        $connection = $claim->connection;
        if ($connection === null || $connection->provider !== 'mercadolibre') {
            throw new RuntimeException('Solo se pueden enviar mensajes de reclamos de Mercado Libre.');
        }

        if (! $claim->canMessageMediator()) {
            throw new RuntimeException('Este reclamo no permite enviar mensaje al mediador ahora.');
        }

        $token = $this->accessToken($connection);
        $claimId = $claim->external_claim_id;
        if ($token === null || ! is_string($claimId) || $claimId === '') {
            throw new RuntimeException('Falta access_token o external_claim_id para enviar el mensaje.');
        }

        $workspace = Workspace::query()->find($claim->workspace_id);
        $dryRun = (bool) ($workspace?->outbound_dry_run ?? true);

        /** @var \App\Integrations\MercadoLibre\Connector\MercadoLibreConnector $connector */
        $connector = $this->registry->get('mercadolibre');
        $connector->sendClaimMessage($claimId, $token, [
            'receiver_role' => 'mediator',
            'message' => $text,
        ], $dryRun);

        if ($dryRun) {
            return ClaimMessage::query()->create([
                'workspace_id' => $claim->workspace_id,
                'connection_id' => $connection->id,
                'claim_id' => $claim->id,
                'external_message_key' => 'dry-run-'.uniqid('', true),
                'sender_role' => 'respondent',
                'receiver_role' => 'mediator',
                'stage' => $claim->stage,
                'message' => $text,
                'sent_at' => now(),
                'meta' => ['dry_run' => true],
            ]);
        }

        $this->sync->execute($claim, refreshFromProvider: true);

        return ClaimMessage::query()
            ->where('claim_id', $claim->id)
            ->where('sender_role', 'respondent')
            ->where('message', $text)
            ->orderByDesc('id')
            ->firstOrFail();
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
}
