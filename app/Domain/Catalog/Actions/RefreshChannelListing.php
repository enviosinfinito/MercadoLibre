<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Integrations\Actions\ResolveSyncProfile;
use App\Jobs\FetchExternalResourceJob;
use App\Models\ChannelListing;
use InvalidArgumentException;
use RuntimeException;

final class RefreshChannelListing
{
    public function __construct(
        private readonly ResolveSyncProfile $resolveSyncProfile,
    ) {}

    public function execute(ChannelListing $listing): ChannelListing
    {
        $listing->loadMissing('connection');

        $connection = $listing->connection;
        if ($connection === null) {
            throw new InvalidArgumentException('La publicación no tiene conexión asociada.');
        }

        if (! in_array($connection->status, ['active', 'connected'], true)) {
            throw new InvalidArgumentException('La conexión no está activa.');
        }

        if ($connection->provider !== 'mercadolibre') {
            throw new InvalidArgumentException('Solo se puede actualizar publicaciones de Mercado Libre.');
        }

        if (! $this->resolveSyncProfile->isEnabled($connection, 'listings')) {
            throw new InvalidArgumentException('La sincronización de publicaciones está deshabilitada.');
        }

        $externalId = $listing->external_item_id;
        if ($externalId === null || $externalId === '') {
            throw new InvalidArgumentException('La publicación no tiene ID externo.');
        }

        try {
            (new FetchExternalResourceJob(
                (int) $listing->workspace_id,
                (int) $listing->connection_id,
                'item',
                (string) $externalId,
                projectSynchronously: true,
                includeOverrides: ['pictures' => true],
            ))->handle();
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'No se pudo actualizar la publicación desde Mercado Libre: '.mb_substr($e->getMessage(), 0, 200),
                previous: $e,
            );
        }

        return $listing->fresh([
            'connection:id,provider,external_user_id,display_name,color',
            'variants.variant:id,sku,name,product_id',
        ]) ?? $listing;
    }
}
