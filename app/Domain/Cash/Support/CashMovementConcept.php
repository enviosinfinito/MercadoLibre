<?php

namespace App\Domain\Cash\Support;

/**
 * Map Mercado Pago ledger types to seller-facing cash concepts.
 */
final class CashMovementConcept
{
    public const SALE = 'sale';

    public const SHIPPING_CREDIT = 'shipping_credit';

    public const SHIPPING_DEBIT = 'shipping_debit';

    public const REFUND = 'refund';

    public const CHARGEBACK = 'chargeback';

    public const DISPUTE = 'dispute';

    public const PAYOUT_HOLD = 'payout_hold';

    public const WITHDRAWAL = 'withdrawal';

    public const CASHBACK = 'cashback';

    public const OTHER = 'other';

    public static function fromLedger(
        ?string $transactionType,
        ?string $entryType = null,
        ?string $externalId = null,
    ): string {
        $tx = strtoupper(trim((string) $transactionType));
        $entry = strtolower(trim((string) $entryType));
        $id = trim((string) $externalId);

        if ($entry === 'withdrawal' || in_array($tx, ['PAYOUTS', 'PAYOUT', 'WITHDRAWAL', 'BANK_TRANSFER'], true)) {
            return self::WITHDRAWAL;
        }
        if ($tx === 'REFUND' || str_starts_with($tx, 'REFUND')) {
            return self::REFUND;
        }
        if ($tx === 'CHARGEBACK' || str_contains($tx, 'CHARGEBACK')) {
            return self::CHARGEBACK;
        }
        if ($tx === 'RESERVE_FOR_PAYOUT') {
            return self::PAYOUT_HOLD;
        }
        if (
            $tx === 'DISPUTE'
            || $tx === 'MEDIATION'
            || str_contains($tx, 'DISPUTE')
            || str_starts_with($tx, 'RESERVE_')
        ) {
            return self::DISPUTE;
        }
        if ($tx === 'SHIPPING' || $tx === 'SETTLEMENT_SHIPPING' || str_contains($tx, 'SHIPPING')) {
            return self::SHIPPING_CREDIT;
        }
        if ($tx === 'CASHBACK' || str_starts_with(strtolower($id), 'cashback_')) {
            return self::CASHBACK;
        }
        if ($tx === 'PAYMENT' || $tx === 'SETTLEMENT') {
            return self::SALE;
        }
        if (self::looksLikeShippingId($id)) {
            return self::SHIPPING_CREDIT;
        }
        if (self::looksLikeMlOrderId($id)) {
            return self::SALE;
        }

        return self::OTHER;
    }

    public static function label(string $concept): string
    {
        return match ($concept) {
            self::SALE => 'Cobro de la venta',
            self::SHIPPING_CREDIT => 'Envío que pagó el comprador',
            self::SHIPPING_DEBIT => 'Débito de envío',
            self::REFUND => 'Reembolso',
            self::CHARGEBACK => 'Contracargo',
            self::DISPUTE => 'Reserva / reclamo',
            self::PAYOUT_HOLD => 'Reserva para retiro',
            self::WITHDRAWAL => 'Retiro al banco',
            self::CASHBACK => 'Ajuste / cashback',
            default => 'Otro movimiento MP',
        };
    }

    public static function labelFor(?string $transactionType, string $concept): string
    {
        $tx = strtoupper(trim((string) $transactionType));

        return match ($tx) {
            'RESERVE_FOR_DISPUTE' => 'Reserva por reclamo',
            'MEDIATION' => 'Mediación (reclamo)',
            'DISPUTE' => 'Reclamo',
            'DISPUTE_SHIPPING' => 'Reclamo de envío',
            'RESERVE_FOR_REFUND' => 'Reserva por reembolso',
            'RESERVE_FOR_BPP_SHIPPING_RETURN' => 'Reserva por devolución',
            'RESERVE_FOR_TIME_PERIOD' => 'Reserva temporal',
            'RESERVE_FOR_DEBT_PAYMENT' => 'Reserva por deuda',
            'RESERVE_FOR_PAYMENT' => 'Reserva de cobro',
            'RESERVE_FOR_PAYOUT' => 'Reserva para retiro',
            'REFUND_SHIPPING' => 'Reembolso de envío',
            'CHARGEBACK_SHIPPING' => 'Contracargo de envío',
            default => self::label($concept),
        };
    }

    public static function hint(string $concept): ?string
    {
        return match ($concept) {
            self::SHIPPING_CREDIT => 'Lo que el comprador pagó de envío en el checkout. No es el costo de envío que te descontaron en el cobro.',
            self::SHIPPING_DEBIT => 'Descuento de logística (no es lo que pagó el comprador).',
            self::SALE => 'Neto del cobro del comprador, ya con comisión, envío e impuestos.',
            self::DISPUTE => 'MP retuvo este dinero por un reclamo o mediación. No está disponible en tu saldo hasta que se resuelva.',
            self::PAYOUT_HOLD => 'MP apartó este monto para un retiro. No es un reclamo.',
            self::CASHBACK => 'Ajuste o cashback reportado por Mercado Pago.',
            self::REFUND => 'Devolución total o parcial al comprador.',
            self::CHARGEBACK => 'Contracargo: el dinero salió de tu saldo.',
            default => null,
        };
    }

    public static function looksLikeMlOrderId(?string $externalId): bool
    {
        $id = trim((string) $externalId);

        return (bool) preg_match('/^20000\d{11}$/', $id);
    }

    public static function looksLikeShippingId(?string $externalId): bool
    {
        $id = trim((string) $externalId);
        if ($id === '' || self::looksLikeMlOrderId($id)) {
            return false;
        }

        return ctype_digit($id) && strlen($id) <= 12;
    }

    /**
     * Coarse reference kind used by Liberaciones fetch/UI.
     */
    public static function referenceKind(?string $externalId, ?string $transactionType = null): string
    {
        return match (self::fromLedger($transactionType, null, $externalId)) {
            self::SHIPPING_CREDIT, self::SHIPPING_DEBIT => 'shipping',
            self::CASHBACK => 'cashback',
            self::OTHER, self::PAYOUT_HOLD => 'other',
            default => 'order',
        };
    }

    /**
     * @return array{key: string, label: string, hint: string|null}
     */
    public static function describe(
        ?string $transactionType,
        ?string $entryType = null,
        ?string $externalId = null,
        ?string $netAmount = null,
    ): array {
        $key = self::fromLedger($transactionType, $entryType, $externalId);
        if (
            $key === self::SHIPPING_CREDIT
            && $netAmount !== null
            && is_numeric($netAmount)
            && bccomp((string) $netAmount, '0', 6) === -1
        ) {
            $key = self::SHIPPING_DEBIT;
        }

        return [
            'key' => $key,
            'label' => $key === self::SHIPPING_DEBIT
                ? self::label(self::SHIPPING_DEBIT)
                : self::labelFor($transactionType, $key),
            'hint' => self::hint($key),
        ];
    }
}
