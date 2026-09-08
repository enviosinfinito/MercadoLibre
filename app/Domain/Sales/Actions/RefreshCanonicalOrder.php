<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Integrations\Actions\ResolveSyncProfile;
use App\Jobs\FetchExternalResourceJob;
use App\Models\Connection;
use App\Models\Order;
use InvalidArgumentException;
use RuntimeException;

final class RefreshCanonicalOrder
{
    public function __construct(
        private readonly ResolveSyncProfile $resolveSyncProfile,
    ) {}

    public function execute(Order $order): Order
    {
        $order->loadMissing('connection');

        $connection = $order->connection;
        if ($connection === null) {
            throw new InvalidArgumentException('La orden no tiene conexión asociada.');
        }

        $externalId = $order->external_order_id;
        if ($externalId === null || $externalId === '') {
            throw new InvalidArgumentException('La orden no tiene ID externo.');
        }

        $this->fetchForConnection($connection, (string) $externalId);

        return $order->fresh([
            'lines',
            'profitSnapshots',
            'connection:id,provider,external_user_id',
            'financialEvents',
        ]) ?? $order;
    }

    /**
     * Fetch (or create) a Mercado Libre order by external id on a connection.
     */
    public function executeForConnection(Connection $connection, string $externalOrderId): Order
    {
        $externalOrderId = trim($externalOrderId);
        if ($externalOrderId === '') {
            throw new InvalidArgumentException('Falta el ID externo de la orden.');
        }

        $this->fetchForConnection($connection, $externalOrderId);

        $order = Order::query()
            ->where('workspace_id', $connection->workspace_id)
            ->where('connection_id', $connection->id)
            ->where('external_order_id', $externalOrderId)
            ->first();

        if ($order === null) {
            throw new RuntimeException(
                'Mercado Libre no devolvió la orden '.$externalOrderId.' (o no se pudo proyectar).'
            );
        }

        return $order->fresh([
            'lines',
            'profitSnapshots',
            'connection:id,provider,external_user_id',
            'financialEvents',
        ]) ?? $order;
    }

    private function fetchForConnection(Connection $connection, string $externalOrderId): void
    {
        if (! in_array($connection->status, ['active', 'connected'], true)) {
            throw new InvalidArgumentException('La conexión no está activa.');
        }

        if ($connection->provider !== 'mercadolibre') {
            throw new InvalidArgumentException('Solo se puede actualizar órdenes de Mercado Libre.');
        }

        if (! $this->resolveSyncProfile->isEnabled($connection, 'orders')) {
            throw new InvalidArgumentException('La sincronización de órdenes está deshabilitada.');
        }

        try {
            // Same pipeline as mass bootstrap: GET /orders/{id} → project → shipments/messages.
            (new FetchExternalResourceJob(
                (int) $connection->workspace_id,
                (int) $connection->id,
                'order',
                $externalOrderId,
                projectSynchronously: true,
            ))->handle();
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'No se pudo actualizar la orden desde Mercado Libre: '.mb_substr($e->getMessage(), 0, 200),
                previous: $e,
            );
        }
    }
}
