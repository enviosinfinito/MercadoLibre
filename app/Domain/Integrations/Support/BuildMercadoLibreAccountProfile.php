<?php

namespace App\Domain\Integrations\Support;

/**
 * Normalize Mercado Libre /users/me into a stable account_profile JSON shape.
 */
final class BuildMercadoLibreAccountProfile
{
    /**
     * @param  array<string, mixed>  $user
     * @return array<string, mixed>
     */
    public function execute(array $user): array
    {
        $tags = is_array($user['tags'] ?? null)
            ? array_values(array_filter($user['tags'], static fn ($t) => is_string($t) && $t !== ''))
            : [];

        return [
            'id' => isset($user['id']) ? (string) $user['id'] : null,
            'nickname' => $this->stringOrNull($user['nickname'] ?? null),
            'first_name' => $this->stringOrNull($user['first_name'] ?? null),
            'last_name' => $this->stringOrNull($user['last_name'] ?? null),
            'gender' => $this->stringOrNull($user['gender'] ?? null),
            'registration_date' => $this->stringOrNull($user['registration_date'] ?? null),
            'country_id' => $this->stringOrNull($user['country_id'] ?? null),
            'email' => $this->stringOrNull($user['email'] ?? null),
            'user_type' => $this->stringOrNull($user['user_type'] ?? null),
            'seller_experience' => $this->stringOrNull($user['seller_experience'] ?? null),
            'points' => isset($user['points']) && is_numeric($user['points']) ? (int) $user['points'] : null,
            'tags' => $tags,
            'identification' => $this->normalizeIdentification($user['identification'] ?? null),
            'phone' => $this->normalizePhone($user['phone'] ?? null),
            'alternative_phone' => $this->normalizePhone($user['alternative_phone'] ?? null),
            'address' => $this->normalizeAddress($user['address'] ?? null),
            'company' => $this->normalizeCompany($user['company'] ?? null),
            'bill_data' => $this->normalizeBillData($user['bill_data'] ?? null),
            'credit' => $this->normalizeCredit($user['credit'] ?? null),
            'status' => $this->normalizeStatus($user['status'] ?? null),
            'buyer_reputation' => $this->normalizeBuyerReputation($user['buyer_reputation'] ?? null),
        ];
    }

    /**
     * @return array{type: string|null, number: string|null}|null
     */
    private function normalizeIdentification(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $type = $this->stringOrNull($value['type'] ?? null);
        $number = $this->stringOrNull($value['number'] ?? null);
        if ($type === null && $number === null) {
            return null;
        }

        return ['type' => $type, 'number' => $number];
    }

    /**
     * @return array{area_code: string|null, number: string|null, extension: string|null, verified: bool|null}|null
     */
    private function normalizePhone(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $area = $this->stringOrNull($value['area_code'] ?? null);
        $number = $this->stringOrNull($value['number'] ?? null);
        $extension = $this->stringOrNull($value['extension'] ?? null);
        $verified = array_key_exists('verified', $value) ? (is_bool($value['verified']) ? $value['verified'] : null) : null;
        if ($area === null && $number === null && $extension === null && $verified === null) {
            return null;
        }

        return [
            'area_code' => $area,
            'number' => $number,
            'extension' => $extension,
            'verified' => $verified,
        ];
    }

    /**
     * @return array{address: string|null, city: string|null, state: string|null, zip_code: string|null}|null
     */
    private function normalizeAddress(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $row = [
            'address' => $this->stringOrNull($value['address'] ?? null),
            'city' => $this->stringOrNull($value['city'] ?? null),
            'state' => $this->stringOrNull($value['state'] ?? null),
            'zip_code' => $this->stringOrNull($value['zip_code'] ?? null),
        ];
        if (array_filter($row, static fn ($v) => $v !== null) === []) {
            return null;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeCompany(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $row = [
            'brand_name' => $this->stringOrNull($value['brand_name'] ?? null),
            'corporate_name' => $this->stringOrNull($value['corporate_name'] ?? null),
            'identification' => $this->stringOrNull($value['identification'] ?? null),
            'city_tax_id' => $this->stringOrNull($value['city_tax_id'] ?? null),
            'state_tax_id' => $this->stringOrNull($value['state_tax_id'] ?? null),
            'cust_type_id' => $this->stringOrNull($value['cust_type_id'] ?? null),
            'soft_descriptor' => $this->stringOrNull($value['soft_descriptor'] ?? null),
        ];
        if (array_filter($row, static fn ($v) => $v !== null) === []) {
            return null;
        }

        return $row;
    }

    /**
     * @return array{accept_credit_note: bool|null}|null
     */
    private function normalizeBillData(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $accept = $value['accept_credit_note'] ?? null;

        return [
            'accept_credit_note' => is_bool($accept) ? $accept : null,
        ];
    }

    /**
     * @return array{consumed: int|null, credit_level_id: string|null, rank: string|null}|null
     */
    private function normalizeCredit(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $row = [
            'consumed' => isset($value['consumed']) && is_numeric($value['consumed']) ? (int) $value['consumed'] : null,
            'credit_level_id' => $this->stringOrNull($value['credit_level_id'] ?? null),
            'rank' => $this->stringOrNull($value['rank'] ?? null),
        ];
        if (array_filter($row, static fn ($v) => $v !== null) === []) {
            return null;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeStatus(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        return [
            'site_status' => $this->stringOrNull($value['site_status'] ?? null),
            'confirmed_email' => is_bool($value['confirmed_email'] ?? null) ? $value['confirmed_email'] : null,
            'required_action' => $this->stringOrNull($value['required_action'] ?? null),
            'mercadoenvios' => $this->stringOrNull($value['mercadoenvios'] ?? null),
            'mercadopago_account_type' => $this->stringOrNull($value['mercadopago_account_type'] ?? null),
            'mercadopago_tc_accepted' => is_bool($value['mercadopago_tc_accepted'] ?? null) ? $value['mercadopago_tc_accepted'] : null,
            'immediate_payment' => is_bool($value['immediate_payment'] ?? null) ? $value['immediate_payment'] : null,
            'user_type' => $this->stringOrNull($value['user_type'] ?? null),
            'sell' => $this->normalizePermissionBlock($value['sell'] ?? null),
            'buy' => $this->normalizePermissionBlock($value['buy'] ?? null),
            'list' => $this->normalizePermissionBlock($value['list'] ?? null),
            'billing' => $this->normalizePermissionBlock($value['billing'] ?? null, withImmediate: false),
            'shopping_cart' => is_array($value['shopping_cart'] ?? null) ? [
                'buy' => $this->stringOrNull($value['shopping_cart']['buy'] ?? null),
                'sell' => $this->stringOrNull($value['shopping_cart']['sell'] ?? null),
            ] : null,
        ];
    }

    /**
     * @return array{allow: bool|null, codes: list<string>, immediate_payment: array{required: bool|null, reasons: list<string>}|null}
     */
    private function normalizePermissionBlock(mixed $value, bool $withImmediate = true): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $codes = is_array($value['codes'] ?? null)
            ? array_values(array_filter($value['codes'], static fn ($c) => is_string($c) && $c !== ''))
            : [];
        $immediate = null;
        if ($withImmediate && is_array($value['immediate_payment'] ?? null)) {
            $reasons = is_array($value['immediate_payment']['reasons'] ?? null)
                ? array_values(array_filter($value['immediate_payment']['reasons'], static fn ($r) => is_string($r) && $r !== ''))
                : [];
            $immediate = [
                'required' => is_bool($value['immediate_payment']['required'] ?? null)
                    ? $value['immediate_payment']['required']
                    : null,
                'reasons' => $reasons,
            ];
        }

        return [
            'allow' => is_bool($value['allow'] ?? null) ? $value['allow'] : null,
            'codes' => $codes,
            'immediate_payment' => $immediate,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeBuyerReputation(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $tags = is_array($value['tags'] ?? null)
            ? array_values(array_filter($value['tags'], static fn ($t) => is_string($t) && $t !== ''))
            : [];
        $transactions = is_array($value['transactions'] ?? null) ? $value['transactions'] : [];

        return [
            'canceled_transactions' => isset($value['canceled_transactions']) && is_numeric($value['canceled_transactions'])
                ? (int) $value['canceled_transactions']
                : null,
            'tags' => $tags,
            'transactions' => [
                'period' => $this->stringOrNull($transactions['period'] ?? null),
                'total' => isset($transactions['total']) && is_numeric($transactions['total'])
                    ? (int) $transactions['total']
                    : null,
                'completed' => isset($transactions['completed']) && is_numeric($transactions['completed'])
                    ? (int) $transactions['completed']
                    : null,
                'canceled' => is_array($transactions['canceled'] ?? null) ? [
                    'total' => isset($transactions['canceled']['total']) && is_numeric($transactions['canceled']['total'])
                        ? (int) $transactions['canceled']['total'] : null,
                    'paid' => isset($transactions['canceled']['paid']) && is_numeric($transactions['canceled']['paid'])
                        ? (int) $transactions['canceled']['paid'] : null,
                ] : null,
                'unrated' => is_array($transactions['unrated'] ?? null) ? [
                    'total' => isset($transactions['unrated']['total']) && is_numeric($transactions['unrated']['total'])
                        ? (int) $transactions['unrated']['total'] : null,
                    'paid' => isset($transactions['unrated']['paid']) && is_numeric($transactions['unrated']['paid'])
                        ? (int) $transactions['unrated']['paid'] : null,
                ] : null,
                'not_yet_rated' => is_array($transactions['not_yet_rated'] ?? null) ? [
                    'total' => isset($transactions['not_yet_rated']['total']) && is_numeric($transactions['not_yet_rated']['total'])
                        ? (int) $transactions['not_yet_rated']['total'] : null,
                    'paid' => isset($transactions['not_yet_rated']['paid']) && is_numeric($transactions['not_yet_rated']['paid'])
                        ? (int) $transactions['not_yet_rated']['paid'] : null,
                    'units' => isset($transactions['not_yet_rated']['units']) && is_numeric($transactions['not_yet_rated']['units'])
                        ? (int) $transactions['not_yet_rated']['units'] : null,
                ] : null,
            ],
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
