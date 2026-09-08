<?php

namespace App\Domain\Finance\Actions;

use App\Integrations\Contracts\Dto\FetchRequest;
use App\Integrations\MercadoLibre\Connector\MercadoLibreConnector;
use App\Integrations\Support\LoggedHttpClient;
use App\Models\Connection;
use App\Models\Order;
use App\Models\RawResourceSnapshot;
use Throwable;

/**
 * Resolves seller shipping cost for an order.
 *
 * Prefer GET /shipments/{id}/costs → senders[].cost (matches ML seller UI "Envíos").
 * Fallbacks: shipment.base_cost / list_cost (less accurate).
 */
final class ResolveMercadoLibreShippingCost
{
    public function __construct(
        private readonly MercadoLibreConnector $connector,
        private readonly LoggedHttpClient $http,
    ) {}

    /**
     * @return array{amount: string, source: string, shipment: array<string, mixed>|null}|null
     */
    public function execute(Order $order, bool $allowFetch = true): ?array
    {
        $metaShipping = is_array($order->meta['shipping'] ?? null) ? $order->meta['shipping'] : [];

        // Already enriched with costs API amount.
        if (isset($metaShipping['seller_cost']) && is_numeric($metaShipping['seller_cost'])
            && bccomp((string) $metaShipping['seller_cost'], '0', 6) === 1) {
            return [
                'amount' => bcadd((string) $metaShipping['seller_cost'], '0', 6),
                'source' => (string) ($metaShipping['seller_cost_source'] ?? 'mercadolibre_shipment_costs_sender'),
                'shipment' => $metaShipping,
            ];
        }

        $raw = $this->rawPayload($order);
        $shipmentId = $metaShipping['id'] ?? $raw['shipping']['id'] ?? null;

        if ($allowFetch && $shipmentId !== null && $shipmentId !== '') {
            $fromCosts = $this->fetchShipmentCosts($order, (string) $shipmentId);
            if ($fromCosts !== null) {
                $this->persistShippingMeta($order, array_merge($metaShipping, [
                    'id' => $shipmentId,
                    'seller_cost' => $fromCosts['amount'],
                    'seller_cost_source' => $fromCosts['source'],
                    'costs' => $fromCosts['raw'],
                ]));

                return [
                    'amount' => $fromCosts['amount'],
                    'source' => $fromCosts['source'],
                    'shipment' => $order->fresh()->meta['shipping'] ?? null,
                ];
            }

            $shipment = $this->fetchShipment($order, (string) $shipmentId);
            if ($shipment !== null) {
                $this->persistShippingMeta($order, array_merge($metaShipping, $this->slimShipment($shipment)));
                $fromFetched = $this->extractSellerCostFallback($shipment);
                if ($fromFetched !== null) {
                    return [
                        'amount' => $fromFetched['amount'],
                        'source' => $fromFetched['source'],
                        'shipment' => $shipment,
                    ];
                }
            }
        }

        $fromMeta = $this->extractSellerCostFallback($metaShipping);
        if ($fromMeta !== null) {
            return [
                'amount' => $fromMeta['amount'],
                'source' => $fromMeta['source'],
                'shipment' => $metaShipping !== [] ? $metaShipping : null,
            ];
        }

        return null;
    }

    /**
     * @return array{amount: string, source: string, raw: array<string, mixed>}|null
     */
    private function fetchShipmentCosts(Order $order, string $shipmentId): ?array
    {
        try {
            $connection = Connection::query()->with('credential')->find($order->connection_id);
            if ($connection === null) {
                return null;
            }
            try {
                $token = app(\App\Domain\Integrations\Actions\EnsureFreshConnectionToken::class)
                    ->execute($connection);
            } catch (\Throwable) {
                return null;
            }

            $base = rtrim((string) config('connectors.mercadolibre.api_base_url'), '/');
            $response = $this->http->get($base.'/shipments/'.$shipmentId.'/costs', [], $token);
            if ($response->failed() || ! is_array($response->json())) {
                return null;
            }

            $payload = $response->json();
            $sellerUserId = (string) ($connection->external_user_id ?? '');
            $amount = null;

            foreach ($payload['senders'] ?? [] as $sender) {
                if (! is_array($sender) || ! isset($sender['cost']) || ! is_numeric($sender['cost'])) {
                    continue;
                }
                $senderId = isset($sender['user_id']) ? (string) $sender['user_id'] : null;
                if ($sellerUserId !== '' && $senderId !== null && $senderId !== $sellerUserId) {
                    continue;
                }
                $amount = bcadd((string) $sender['cost'], '0', 6);
                break;
            }

            // Fallback: first sender cost if seller id didn't match.
            if ($amount === null) {
                foreach ($payload['senders'] ?? [] as $sender) {
                    if (is_array($sender) && isset($sender['cost']) && is_numeric($sender['cost'])) {
                        $amount = bcadd((string) $sender['cost'], '0', 6);
                        break;
                    }
                }
            }

            if ($amount === null || bccomp($amount, '0', 6) !== 1) {
                return null;
            }

            return [
                'amount' => $amount,
                'source' => 'mercadolibre_shipment_costs_sender',
                'raw' => $payload,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $shipping
     * @return array{amount: string, source: string}|null
     */
    private function extractSellerCostFallback(array $shipping): ?array
    {
        // Prefer list_cost over base_cost — closer to ML UI when costs API unavailable.
        $option = is_array($shipping['shipping_option'] ?? null) ? $shipping['shipping_option'] : [];
        if (isset($option['list_cost']) && is_numeric($option['list_cost'])
            && bccomp((string) $option['list_cost'], '0', 6) === 1) {
            return [
                'amount' => bcadd((string) $option['list_cost'], '0', 6),
                'source' => 'mercadolibre_shipment_list_cost',
            ];
        }

        if (isset($shipping['base_cost']) && is_numeric($shipping['base_cost'])
            && bccomp((string) $shipping['base_cost'], '0', 6) === 1) {
            return [
                'amount' => bcadd((string) $shipping['base_cost'], '0', 6),
                'source' => 'mercadolibre_shipment_base_cost',
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rawPayload(Order $order): ?array
    {
        if (! $order->raw_snapshot_id) {
            return null;
        }

        $snapshot = RawResourceSnapshot::query()->find($order->raw_snapshot_id);

        return is_array($snapshot?->payload) ? $snapshot->payload : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchShipment(Order $order, string $shipmentId): ?array
    {
        try {
            $connection = Connection::query()->with('credential')->find($order->connection_id);
            if ($connection === null) {
                return null;
            }
            try {
                $token = app(\App\Domain\Integrations\Actions\EnsureFreshConnectionToken::class)
                    ->execute($connection);
            } catch (\Throwable) {
                return null;
            }

            $result = $this->connector->fetch(new FetchRequest(
                resource: 'shipments',
                externalId: $shipmentId,
                options: ['access_token' => $token],
            ));

            return is_array($result->payload) ? $result->payload : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $shipment
     * @return array<string, mixed>
     */
    private function slimShipment(array $shipment): array
    {
        return [
            'id' => $shipment['id'] ?? null,
            'status' => $shipment['status'] ?? null,
            'logistic_type' => $shipment['logistic_type'] ?? null,
            'base_cost' => $shipment['base_cost'] ?? null,
            'shipping_option' => $shipment['shipping_option'] ?? null,
            'tracking_number' => $shipment['tracking_number'] ?? null,
            'cost_components' => $shipment['cost_components'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $shipping
     */
    private function persistShippingMeta(Order $order, array $shipping): void
    {
        $meta = is_array($order->meta) ? $order->meta : [];
        $meta['shipping'] = $shipping;
        $order->meta = $meta;
        $order->save();
    }
}
