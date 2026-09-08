<?php

namespace Tests\Unit;

use App\Domain\Integrations\Support\BuildMercadoLibreAccountProfile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BuildMercadoLibreAccountProfileTest extends TestCase
{
    #[Test]
    public function normalizes_users_me_payload(): void
    {
        $profile = (new BuildMercadoLibreAccountProfile)->execute([
            'id' => 1486512189,
            'nickname' => 'QUALYSHOP1998',
            'first_name' => 'Ana',
            'last_name' => 'Lopez',
            'registration_date' => '2019-01-01T00:00:00.000-06:00',
            'country_id' => 'MX',
            'email' => 'ana@example.com',
            'user_type' => 'normal',
            'seller_experience' => 'ADVANCED',
            'points' => 120,
            'tags' => ['normal', 'eshop'],
            'identification' => ['type' => 'RFC', 'number' => 'XAXX010101000'],
            'phone' => ['area_code' => '55', 'number' => '12345678', 'extension' => '', 'verified' => true],
            'alternative_phone' => ['area_code' => '', 'number' => '', 'extension' => ''],
            'address' => [
                'address' => 'Calle 1',
                'city' => 'CDMX',
                'state' => 'MX-CMX',
                'zip_code' => '01000',
            ],
            'company' => [
                'corporate_name' => 'Qualy SA',
                'brand_name' => 'Qualy',
                'identification' => '',
            ],
            'bill_data' => ['accept_credit_note' => true],
            'credit' => ['consumed' => 10, 'credit_level_id' => 'MLM5', 'rank' => 'advanced'],
            'status' => [
                'site_status' => 'active',
                'confirmed_email' => true,
                'required_action' => '',
                'mercadoenvios' => 'accepted',
                'mercadopago_account_type' => 'personal',
                'mercadopago_tc_accepted' => true,
                'immediate_payment' => false,
                'sell' => ['allow' => true, 'codes' => [], 'immediate_payment' => ['required' => false, 'reasons' => []]],
                'buy' => ['allow' => true, 'codes' => [], 'immediate_payment' => ['required' => false, 'reasons' => []]],
                'list' => ['allow' => true, 'codes' => [], 'immediate_payment' => ['required' => false, 'reasons' => []]],
                'billing' => ['allow' => true, 'codes' => []],
                'shopping_cart' => ['buy' => 'allowed', 'sell' => 'allowed'],
            ],
            'buyer_reputation' => [
                'canceled_transactions' => 2,
                'tags' => ['user_info_verified'],
                'transactions' => [
                    'period' => 'historic',
                    'total' => 5,
                    'completed' => 3,
                    'canceled' => ['total' => 2, 'paid' => 1],
                ],
            ],
        ]);

        $this->assertSame('1486512189', $profile['id']);
        $this->assertSame('QUALYSHOP1998', $profile['nickname']);
        $this->assertSame('ana@example.com', $profile['email']);
        $this->assertSame(['normal', 'eshop'], $profile['tags']);
        $this->assertSame('55', $profile['phone']['area_code']);
        $this->assertNull($profile['alternative_phone']);
        $this->assertSame('CDMX', $profile['address']['city']);
        $this->assertSame('Qualy SA', $profile['company']['corporate_name']);
        $this->assertTrue($profile['status']['sell']['allow']);
        $this->assertSame('active', $profile['status']['site_status']);
        $this->assertSame(2, $profile['buyer_reputation']['canceled_transactions']);
    }
}
