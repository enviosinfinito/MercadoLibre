<?php

namespace App\Domain\PostSale\Actions;

use App\Domain\Integrations\Actions\EnsureFreshConnectionToken;
use App\Integrations\Contracts\ConnectorRegistry;
use App\Models\Claim;
use App\Models\ClaimMessage;
use RuntimeException;
use Throwable;

final class DownloadClaimAttachment
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly EnsureFreshConnectionToken $ensureToken,
    ) {}

    /**
     * @return array{body: string, content_type: string, original_filename: string, filename: string}
     */
    public function execute(Claim $claim, string $filename): array
    {
        $filename = trim($filename);
        if ($filename === '' || ! preg_match('/^[A-Za-z0-9._-]+$/', $filename)) {
            throw new RuntimeException('Nombre de adjunto inválido.');
        }

        $meta = $this->findAttachmentMeta($claim, $filename);
        if ($meta === null) {
            throw new RuntimeException('Adjunto no encontrado en este reclamo.');
        }

        $claim->loadMissing('connection');
        $connection = $claim->connection;
        if ($connection === null || $connection->provider !== 'mercadolibre') {
            throw new RuntimeException('Solo se pueden descargar adjuntos de reclamos de Mercado Libre.');
        }

        $claimId = $claim->external_claim_id;
        if (! is_string($claimId) || $claimId === '') {
            throw new RuntimeException('Falta external_claim_id para descargar el adjunto.');
        }

        try {
            $token = $this->ensureToken->execute($connection);
        } catch (Throwable $e) {
            throw new RuntimeException('No se pudo obtener el access_token: '.$e->getMessage(), 0, $e);
        }

        /** @var \App\Integrations\MercadoLibre\Connector\MercadoLibreConnector $connector */
        $connector = $this->registry->get('mercadolibre');
        $downloaded = $connector->downloadClaimAttachment($claimId, $filename, $token);

        $original = $meta['original_filename'] ?? null;
        $originalFilename = is_string($original) && $original !== ''
            ? $original
            : $filename;

        $contentType = $downloaded['content_type'];
        if (
            ($contentType === 'application/octet-stream' || $contentType === '')
            && is_string($meta['type'] ?? null)
            && $meta['type'] !== ''
        ) {
            $contentType = (string) $meta['type'];
        }

        return [
            'body' => $downloaded['body'],
            'content_type' => $contentType,
            'original_filename' => $originalFilename,
            'filename' => $filename,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findAttachmentMeta(Claim $claim, string $filename): ?array
    {
        $messages = ClaimMessage::query()
            ->where('claim_id', $claim->id)
            ->whereNotNull('meta')
            ->get(['id', 'meta']);

        foreach ($messages as $message) {
            $attachments = $message->meta['attachments'] ?? null;
            if (! is_array($attachments)) {
                continue;
            }
            foreach ($attachments as $attachment) {
                if (! is_array($attachment)) {
                    continue;
                }
                if (($attachment['filename'] ?? null) === $filename) {
                    return $attachment;
                }
            }
        }

        return null;
    }
}
