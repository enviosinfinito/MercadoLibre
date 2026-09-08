<?php

namespace App\Integrations\Sync;

use InvalidArgumentException;

final class SyncResourceCatalog
{
    /**
     * @return list<SyncResourceDefinition>
     */
    public function forProvider(string $provider): array
    {
        return match ($provider) {
            'mercadolibre' => $this->mercadoLibre(),
            'amazon' => $this->amazon(),
            'ecart' => $this->ecart(),
            default => throw new InvalidArgumentException("Unknown sync catalog provider: {$provider}"),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forProviderAsArray(string $provider): array
    {
        return array_map(
            static fn (SyncResourceDefinition $def) => $def->toArray(),
            $this->forProvider($provider),
        );
    }

    public function definition(string $provider, string $resourceKey): ?SyncResourceDefinition
    {
        foreach ($this->forProvider($provider) as $definition) {
            if ($definition->key === $resourceKey) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Map webhook/fetch resource_type aliases to catalog resource_key.
     */
    public function resolveResourceKey(string $resourceType): string
    {
        $normalized = strtolower(trim($resourceType));

        return match ($normalized) {
            'item', 'items', 'listing', 'listings' => 'listings',
            'order', 'orders' => 'orders',
            'payment', 'payments' => 'payments',
            'shipment', 'shipments' => 'shipments',
            'question', 'questions' => 'questions',
            'message', 'messages' => 'messages',
            'claim', 'claims' => 'claims',
            'fee', 'fees', 'order_fee', 'order_fees' => 'order_fees',
            'ad', 'ads', 'advertising' => 'ads',
            'report', 'reports' => 'reports',
            default => $normalized,
        };
    }

    /**
     * @return list<SyncResourceDefinition>
     */
    private function mercadoLibre(): array
    {
        return [
            new SyncResourceDefinition(
                key: 'listings',
                label: 'Publicaciones',
                domain: 'catalog',
                description: 'Items activos/pausados del vendedor, con matching a SKUs internos.',
                costHint: '1 búsqueda paginada + multiget por página',
                defaultEnabled: true,
                fieldGroups: [
                    new SyncFieldGroup('raw_snapshot', 'Snapshot crudo', 'Guarda el JSON completo del item.', 'Almacenamiento DB', true),
                    new SyncFieldGroup('price', 'Precio', 'Proyecta price a variantes de canal.', '', true),
                    new SyncFieldGroup('stock', 'Stock de canal', 'Proyecta available_quantity.', '', true),
                    new SyncFieldGroup('pictures', 'Imágenes', 'URLs de pictures del listing.', '', false),
                    new SyncFieldGroup('description', 'Descripción', 'Texto plano vía /items/{id}/description.', '+1 request por ítem; ML no tiene batch', false),
                    new SyncFieldGroup('attributes', 'Atributos', 'Guarda attributes del item en meta/raw.', '', false),
                    new SyncFieldGroup(
                        'purchase_experience',
                        'Experiencia de compra',
                        'Score, problemas y remedies vía /reputation/.../purchase_experience/integrators.',
                        '+1 request por ítem (o UP)',
                        false,
                    ),
                ],
                modes: ['bootstrap', 'webhook'],
                defaultConfig: [
                    'statuses' => ['active'],
                ],
            ),
            new SyncResourceDefinition(
                key: 'orders',
                label: 'Órdenes',
                domain: 'sales',
                description: 'Pedidos vía webhook y bootstrap histórico (periodo configurable).',
                costHint: '1 search paginado + GET /orders/{id} por orden',
                defaultEnabled: true,
                fieldGroups: [
                    new SyncFieldGroup('raw_snapshot', 'Snapshot crudo', 'JSON completo de la orden.', '', true),
                    new SyncFieldGroup('lines', 'Líneas', 'Items, cantidades y precios.', '', true),
                    new SyncFieldGroup('buyer', 'Comprador', 'IDs / datos básicos del buyer.', '', true),
                    new SyncFieldGroup('shipping', 'Envío en orden', 'Campos shipping embebidos.', '', false),
                ],
                modes: ['webhook', 'bootstrap'],
                defaultConfig: [
                    'lookback_days' => 90,
                ],
            ),
            new SyncResourceDefinition(
                key: 'order_fees',
                label: 'Comisiones / fees',
                domain: 'finance',
                description: 'Eventos financieros esperados/reales de comisiones de venta.',
                costHint: 'Hoy estimado local; API de fees en roadmap',
                defaultEnabled: true,
                fieldGroups: [
                    new SyncFieldGroup('expected', 'Fees esperados', 'Estima o registra fees al cerrar orden.', '', true),
                ],
                modes: ['webhook'],
                dependsOn: ['orders'],
            ),
            new SyncResourceDefinition(
                key: 'payments',
                label: 'Pagos',
                domain: 'finance',
                description: 'Notificaciones y detalle de payments → marketplace_payments + reconciliación.',
                costHint: '1 GET /payments/{id} o /collections/{id} por evento',
                defaultEnabled: true,
                fieldGroups: [
                    new SyncFieldGroup('raw_snapshot', 'Snapshot crudo', '', '', true),
                ],
                modes: ['webhook'],
                dependsOn: ['orders'],
            ),
            new SyncResourceDefinition(
                key: 'shipments',
                label: 'Envíos',
                domain: 'post_sale',
                description: 'Estado de fulfillment / shipments.',
                costHint: '1 GET /shipments/{id} por evento',
                defaultEnabled: true,
                fieldGroups: [
                    new SyncFieldGroup('raw_snapshot', 'Snapshot crudo', '', '', true),
                ],
                modes: ['webhook'],
                dependsOn: ['orders'],
            ),
            new SyncResourceDefinition(
                key: 'questions',
                label: 'Preguntas',
                domain: 'post_sale',
                description: 'Preguntas de compradores en publicaciones.',
                costHint: 'Search + fetch por pregunta',
                defaultEnabled: true,
                fieldGroups: [
                    new SyncFieldGroup('raw_snapshot', 'Snapshot crudo', '', '', true),
                    new SyncFieldGroup('answer', 'Respuesta', 'Incluye texto y fecha de respuesta', '', true),
                ],
                modes: ['webhook', 'bootstrap'],
            ),
            new SyncResourceDefinition(
                key: 'messages',
                label: 'Mensajes',
                domain: 'post_sale',
                description: 'Mensajería postventa por orden/pack.',
                costHint: 'Con órdenes (bootstrap/refresh) + webhook',
                defaultEnabled: true,
                fieldGroups: [
                    new SyncFieldGroup('raw_snapshot', 'Snapshot crudo', '', '', true),
                ],
                modes: ['webhook', 'bootstrap'],
                dependsOn: ['orders'],
            ),
            new SyncResourceDefinition(
                key: 'claims',
                label: 'Reclamos',
                domain: 'post_sale',
                description: 'Claims / mediaciones vía webhook y bootstrap histórico.',
                costHint: '1 search paginado + GET /post-purchase/v1/claims/{id} por reclamo',
                defaultEnabled: true,
                fieldGroups: [
                    new SyncFieldGroup('raw_snapshot', 'Snapshot crudo', 'JSON completo del reclamo.', '', true),
                ],
                modes: ['webhook', 'bootstrap'],
                dependsOn: ['orders'],
            ),
            new SyncResourceDefinition(
                key: 'ads',
                label: 'Publicidad / Ads',
                domain: 'ads',
                description: 'Product Ads: advertisers, campañas y gasto diario por ítem; atribuye ACoS blended al P&L.',
                costHint: 'GET advertisers + campaigns/items metrics (api-version 2), lookback hasta 90 días',
                defaultEnabled: false,
                fieldGroups: [
                    new SyncFieldGroup('spend', 'Gasto diario', 'cost/clicks/ROAS por ítem y campaña.', '', true),
                    new SyncFieldGroup('attribution', 'Atribución P&L', 'expected_advertising (ACoS blended).', '', true),
                ],
                modes: ['bootstrap'],
            ),
        ];
    }

    /**
     * @return list<SyncResourceDefinition>
     */
    private function amazon(): array
    {
        return [
            new SyncResourceDefinition(
                key: 'listings',
                label: 'Listings',
                domain: 'catalog',
                description: 'Catálogo SP-API / listings items.',
                defaultEnabled: true,
                fieldGroups: [
                    new SyncFieldGroup('raw_snapshot', 'Snapshot crudo', '', '', true),
                    new SyncFieldGroup('price', 'Precio', '', '', true),
                    new SyncFieldGroup('stock', 'Inventory', '', '', false),
                ],
                modes: ['bootstrap'],
            ),
            new SyncResourceDefinition(
                key: 'orders',
                label: 'Órdenes',
                domain: 'sales',
                description: 'Orders vía SQS / Reports.',
                defaultEnabled: true,
                fieldGroups: [
                    new SyncFieldGroup('raw_snapshot', 'Snapshot crudo', '', '', true),
                    new SyncFieldGroup('lines', 'Líneas', '', '', true),
                ],
                modes: ['webhook', 'bootstrap'],
            ),
            new SyncResourceDefinition(
                key: 'reports',
                label: 'Reports',
                domain: 'ops',
                description: 'Reportes SP-API (inventory, orders, etc.).',
                defaultEnabled: false,
                fieldGroups: [
                    new SyncFieldGroup('raw_snapshot', 'Snapshot crudo', '', '', true),
                ],
                modes: ['bootstrap'],
            ),
            new SyncResourceDefinition(
                key: 'shipments',
                label: 'Fulfillment',
                domain: 'post_sale',
                defaultEnabled: false,
                fieldGroups: [
                    new SyncFieldGroup('raw_snapshot', 'Snapshot crudo', '', '', true),
                ],
                modes: ['webhook'],
            ),
        ];
    }

    /**
     * @return list<SyncResourceDefinition>
     */
    private function ecart(): array
    {
        return [
            new SyncResourceDefinition(
                key: 'listings',
                label: 'Publicaciones',
                domain: 'catalog',
                defaultEnabled: true,
                fieldGroups: [
                    new SyncFieldGroup('raw_snapshot', 'Snapshot crudo', '', '', true),
                ],
                modes: ['bootstrap'],
            ),
            new SyncResourceDefinition(
                key: 'orders',
                label: 'Órdenes',
                domain: 'sales',
                defaultEnabled: true,
                fieldGroups: [
                    new SyncFieldGroup('raw_snapshot', 'Snapshot crudo', '', '', true),
                    new SyncFieldGroup('lines', 'Líneas', '', '', true),
                ],
                modes: ['webhook'],
            ),
        ];
    }
}
