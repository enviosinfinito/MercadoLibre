<?php

namespace App\Integrations\MercadoLibre\Support;

final class MercadoLibreWebhookTopic
{
    /**
     * Normalize ML notification topic into a stable endpoint_group key.
     * Example: orders_v2 → webhooks.orders_v2
     */
    public static function endpointGroup(mixed $topic): string
    {
        $normalized = self::normalize($topic);

        return $normalized === '' ? 'webhooks' : 'webhooks.'.$normalized;
    }

    public static function normalize(mixed $topic): string
    {
        if (! is_string($topic) && ! is_int($topic)) {
            return '';
        }

        $value = strtolower(trim((string) $topic));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^a-z0-9_\-]+/', '_', $value) ?? $value;

        return trim($value, '_');
    }

    /**
     * Extract topic from endpoint_group like webhooks.orders_v2.
     */
    public static function fromEndpointGroup(?string $endpointGroup): ?string
    {
        if (! is_string($endpointGroup) || $endpointGroup === '') {
            return null;
        }

        if ($endpointGroup === 'webhooks') {
            return null;
        }

        if (str_starts_with($endpointGroup, 'webhooks.')) {
            $topic = substr($endpointGroup, strlen('webhooks.'));

            return $topic !== '' ? $topic : null;
        }

        return null;
    }

    public static function label(?string $topic): string
    {
        return match ($topic) {
            'orders', 'orders_v2' => 'Órdenes',
            'items' => 'Publicaciones',
            'shipments' => 'Envíos',
            'payments' => 'Pagos',
            'questions' => 'Preguntas',
            'messages' => 'Mensajes',
            'claims', 'claims_actions' => 'Reclamos',
            null, '' => 'Webhook',
            default => $topic,
        };
    }
}
