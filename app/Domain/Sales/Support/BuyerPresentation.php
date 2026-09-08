<?php

namespace App\Domain\Sales\Support;

final class BuyerPresentation
{
    /**
     * @param  array<string, mixed>|null  $buyer
     * @return array<string, mixed>|null
     */
    public static function metaFromProviderBuyer(?array $buyer): ?array
    {
        if ($buyer === null || $buyer === []) {
            return null;
        }

        $phone = is_array($buyer['phone'] ?? null) ? $buyer['phone'] : null;
        $altPhone = is_array($buyer['alternative_phone'] ?? null) ? $buyer['alternative_phone'] : null;
        $billing = is_array($buyer['billing_info'] ?? null) ? $buyer['billing_info'] : null;

        $meta = array_filter([
            'id' => isset($buyer['id']) ? (string) $buyer['id'] : null,
            'nickname' => isset($buyer['nickname']) ? (string) $buyer['nickname'] : null,
            'first_name' => isset($buyer['first_name']) ? (string) $buyer['first_name'] : null,
            'last_name' => isset($buyer['last_name']) ? (string) $buyer['last_name'] : null,
            'email' => isset($buyer['email']) ? (string) $buyer['email'] : null,
            'phone' => $phone,
            'alternative_phone' => $altPhone,
            'billing_info' => $billing,
        ], static fn ($value) => $value !== null && $value !== '');

        return $meta === [] ? null : $meta;
    }

    /**
     * Structured buyer label for stacked UI (primary + optional secondary).
     *
     * @param  array<string, mixed>|null  $metaBuyer
     * @return array{primary: string, secondary: ?string, primary_kind: string, secondary_kind: ?string}|null
     */
    public static function summaryParts(?string $buyerExternalId, ?array $metaBuyer): ?array
    {
        $nickname = is_string($metaBuyer['nickname'] ?? null) ? trim((string) $metaBuyer['nickname']) : '';
        $first = is_string($metaBuyer['first_name'] ?? null) ? trim((string) $metaBuyer['first_name']) : '';
        $last = is_string($metaBuyer['last_name'] ?? null) ? trim((string) $metaBuyer['last_name']) : '';
        $fullName = trim($first.' '.$last);

        if ($fullName !== '' && $nickname !== '') {
            return [
                'primary' => $fullName,
                'secondary' => $nickname,
                'primary_kind' => 'name',
                'secondary_kind' => 'code',
            ];
        }

        if ($fullName !== '') {
            return [
                'primary' => $fullName,
                'secondary' => null,
                'primary_kind' => 'name',
                'secondary_kind' => null,
            ];
        }

        if ($nickname !== '') {
            return [
                'primary' => $nickname,
                'secondary' => null,
                'primary_kind' => 'code',
                'secondary_kind' => null,
            ];
        }

        if ($buyerExternalId !== null && $buyerExternalId !== '') {
            return [
                'primary' => 'Comprador #'.$buyerExternalId,
                'secondary' => null,
                'primary_kind' => 'label',
                'secondary_kind' => null,
            ];
        }

        return null;
    }

    /**
     * Short label for lists / order panel.
     *
     * @param  array<string, mixed>|null  $metaBuyer
     */
    public static function summary(?string $buyerExternalId, ?array $metaBuyer): ?string
    {
        $parts = self::summaryParts($buyerExternalId, $metaBuyer);
        if ($parts === null) {
            return null;
        }

        if ($parts['secondary'] !== null && $parts['secondary'] !== '') {
            // Legacy single-line: code · name (keeps prior search/display order)
            if ($parts['primary_kind'] === 'name' && $parts['secondary_kind'] === 'code') {
                return $parts['secondary'].' · '.$parts['primary'];
            }

            return $parts['primary'].' · '.$parts['secondary'];
        }

        return $parts['primary'];
    }

    /**
     * @param  array<string, mixed>|null  $phone
     */
    public static function formatPhone(?array $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $area = trim((string) ($phone['area_code'] ?? ''));
        $number = trim((string) ($phone['number'] ?? ''));
        if ($area === '' && $number === '') {
            return null;
        }

        return trim($area.' '.$number);
    }
}
