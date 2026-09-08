<?php

namespace App\Domain\Inventory\Support;

final class FullStockOperationType
{
    public const FAMILY_ALL = 'all';

    public const FAMILY_INBOUND = 'inbound';

    public const FAMILY_SALE = 'sale';

    public const FAMILY_RETURN = 'return';

    public const FAMILY_CANCELLATION = 'cancellation';

    public const FAMILY_TRANSFER = 'transfer';

    public const FAMILY_QUARANTINE = 'quarantine';

    public const FAMILY_WITHDRAWAL = 'withdrawal';

    public const FAMILY_ADJUSTMENT = 'adjustment';

    public const FAMILY_OTHER = 'other';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'INBOUND_RECEPTION' => 'Ingreso',
            'FISCAL_COVERAGE_AJUSTMENT' => 'Ajuste cobertura fiscal',
            'FISCAL_COVERAGE_ADJUSTMENT' => 'Ajuste cobertura fiscal',
            'SALE_CONFIRMATION' => 'Venta',
            'SALE_CANCELATION' => 'Cancelación de venta',
            'SALE_CANCELLATION' => 'Cancelación de venta',
            'SALE_DELIVERY_CANCELATION' => 'Venta no entregada',
            'SALE_DELIVERY_CANCELLATION' => 'Venta no entregada',
            'SALE_RETURN' => 'Devolución',
            'WITHDRAWAL_RESERVATION' => 'Retiro: reserva',
            'WITHDRAWAL_CANCELATION' => 'Retiro: cancelación',
            'WITHDRAWAL_CANCELLATION' => 'Retiro: cancelación',
            'WITHDRAWAL_DELIVERY' => 'Retiro: entrega',
            'WITHDRAWAL_DISCARDED' => 'Retiro: descarte',
            'TRANSFER_RESERVATION' => 'Transferencia: reserva',
            'TRANSFER_AJUSTMENT' => 'Transferencia: ajuste',
            'TRANSFER_ADJUSTMENT' => 'Transferencia: ajuste',
            'TRANSFER_DELIVERY' => 'Transferencia: entrega',
            'QUARANTINE_RESERVATION' => 'Cuarentena: reserva',
            'QUARANTINE_RESTOCK' => 'Cuarentena: reingreso',
            'LOST_REFUND' => 'Pérdida / reembolso',
            'DISPOSED_TAINED' => 'Descarte: contaminado',
            'DISPOSED_EXPIRED' => 'Descarte: vencido',
            'REMOVAL_RESERVATION' => 'Retiro QA: reserva',
            'REMOVAL_COMPLETION' => 'Retiro QA: completado',
            'STRANDED_DISPOSAL_REMOVAL' => 'Retiro por falta de rotación',
            'AJUSTEMENT' => 'Ajuste de stock',
            'ADJUSTMENT' => 'Ajuste de stock',
            'STOCK_AUDIT' => 'Auditoría de stock',
            'IDENTIFICATION_PROBLEM_REMOVE' => 'Reidentificación: baja',
            'IDENTIFICATION_PROBLEM_ADD' => 'Reidentificación: alta',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function familyLabels(): array
    {
        return [
            self::FAMILY_ALL => 'Todas',
            self::FAMILY_INBOUND => 'Ingreso',
            self::FAMILY_SALE => 'Venta',
            self::FAMILY_RETURN => 'Devolución',
            self::FAMILY_CANCELLATION => 'Cancelación',
            self::FAMILY_TRANSFER => 'Transferencia',
            self::FAMILY_QUARANTINE => 'Cuarentena',
            self::FAMILY_WITHDRAWAL => 'Retiro',
            self::FAMILY_ADJUSTMENT => 'Ajuste',
            self::FAMILY_OTHER => 'Otros',
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function familyTypes(): array
    {
        return [
            self::FAMILY_INBOUND => ['INBOUND_RECEPTION'],
            self::FAMILY_SALE => ['SALE_CONFIRMATION'],
            self::FAMILY_RETURN => ['SALE_RETURN'],
            self::FAMILY_CANCELLATION => [
                'SALE_CANCELATION',
                'SALE_CANCELLATION',
                'SALE_DELIVERY_CANCELATION',
                'SALE_DELIVERY_CANCELLATION',
            ],
            self::FAMILY_TRANSFER => [
                'TRANSFER_RESERVATION',
                'TRANSFER_AJUSTMENT',
                'TRANSFER_ADJUSTMENT',
                'TRANSFER_DELIVERY',
            ],
            self::FAMILY_QUARANTINE => [
                'QUARANTINE_RESERVATION',
                'QUARANTINE_RESTOCK',
                'LOST_REFUND',
                'DISPOSED_TAINED',
                'DISPOSED_EXPIRED',
            ],
            self::FAMILY_WITHDRAWAL => [
                'WITHDRAWAL_RESERVATION',
                'WITHDRAWAL_CANCELATION',
                'WITHDRAWAL_CANCELLATION',
                'WITHDRAWAL_DELIVERY',
                'WITHDRAWAL_DISCARDED',
                'REMOVAL_RESERVATION',
                'REMOVAL_COMPLETION',
                'STRANDED_DISPOSAL_REMOVAL',
            ],
            self::FAMILY_ADJUSTMENT => [
                'FISCAL_COVERAGE_AJUSTMENT',
                'FISCAL_COVERAGE_ADJUSTMENT',
                'AJUSTEMENT',
                'ADJUSTMENT',
                'STOCK_AUDIT',
                'IDENTIFICATION_PROBLEM_REMOVE',
                'IDENTIFICATION_PROBLEM_ADD',
            ],
        ];
    }

    /**
     * @return list<string>|null null = no filter (all); empty list = "other" (handled separately)
     */
    public static function typesForFamily(string $family): ?array
    {
        $normalized = strtolower(trim($family));
        if ($normalized === '' || $normalized === self::FAMILY_ALL) {
            return null;
        }
        if ($normalized === self::FAMILY_OTHER) {
            return [];
        }

        return self::familyTypes()[$normalized] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function allKnownTypes(): array
    {
        $all = [];
        foreach (self::familyTypes() as $types) {
            foreach ($types as $type) {
                $all[] = $type;
            }
        }

        return $all;
    }

    public static function familyForType(string $type): string
    {
        $normalized = self::normalize($type);
        foreach (self::familyTypes() as $family => $types) {
            if (in_array($normalized, $types, true)) {
                return $family;
            }
        }

        return self::FAMILY_OTHER;
    }

    public static function pillVariant(string $type): string
    {
        return match (self::familyForType($type)) {
            self::FAMILY_INBOUND => 'success',
            self::FAMILY_SALE => 'secondary',
            self::FAMILY_RETURN => 'warning',
            self::FAMILY_CANCELLATION, self::FAMILY_QUARANTINE => 'danger',
            self::FAMILY_WITHDRAWAL => 'warning',
            self::FAMILY_TRANSFER, self::FAMILY_ADJUSTMENT => 'muted',
            default => 'secondary',
        };
    }

    public static function label(string $type): string
    {
        $normalized = strtoupper(trim($type));

        return self::labels()[$normalized] ?? $normalized;
    }

    public static function normalize(string $type): string
    {
        return strtoupper(trim($type));
    }

    public static function isSaleConfirmation(string $type): bool
    {
        return self::normalize($type) === 'SALE_CONFIRMATION';
    }
}
