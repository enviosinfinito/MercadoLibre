<?php

namespace Tests\Unit\Cash;

use App\Domain\Cash\Support\CashMovementConcept;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CashMovementConceptTest extends TestCase
{
    #[Test]
    public function maps_mp_types_to_business_labels(): void
    {
        $this->assertSame(CashMovementConcept::SALE, CashMovementConcept::fromLedger('PAYMENT', 'release', '2000017840106932'));
        $this->assertSame(CashMovementConcept::SALE, CashMovementConcept::fromLedger('SETTLEMENT', 'settlement', '2000017840106932'));
        $this->assertSame(CashMovementConcept::SHIPPING_CREDIT, CashMovementConcept::fromLedger('SHIPPING', 'release', '47724611357'));
        $this->assertSame(CashMovementConcept::SHIPPING_CREDIT, CashMovementConcept::fromLedger('SETTLEMENT_SHIPPING', 'settlement', '2000017840106932'));
        $this->assertSame(CashMovementConcept::REFUND, CashMovementConcept::fromLedger('REFUND', 'refund', '2000017840106932'));
        $this->assertSame(CashMovementConcept::CHARGEBACK, CashMovementConcept::fromLedger('CHARGEBACK', 'chargeback', null));
        $this->assertSame(CashMovementConcept::DISPUTE, CashMovementConcept::fromLedger('RESERVE_FOR_DISPUTE', 'release', '2000017825798198'));
        $this->assertSame(CashMovementConcept::DISPUTE, CashMovementConcept::fromLedger('MEDIATION', 'release', '2000017825798198'));
        $this->assertSame('Reserva por reclamo', CashMovementConcept::describe('RESERVE_FOR_DISPUTE', 'release', '2000017825798198')['label']);
        $this->assertSame(CashMovementConcept::WITHDRAWAL, CashMovementConcept::fromLedger('PAYOUTS', 'withdrawal', '172487795506'));
        $this->assertSame(CashMovementConcept::CASHBACK, CashMovementConcept::fromLedger(null, 'adjustment', 'cashback_abc'));

        $this->assertSame('Cobro de la venta', CashMovementConcept::label(CashMovementConcept::SALE));
        $this->assertSame('Envío que pagó el comprador', CashMovementConcept::label(CashMovementConcept::SHIPPING_CREDIT));
        $this->assertSame('Retiro al banco', CashMovementConcept::label(CashMovementConcept::WITHDRAWAL));
    }

    #[Test]
    public function describe_uses_specific_reserve_and_mediation_labels(): void
    {
        $this->assertSame('Mediación (reclamo)', CashMovementConcept::describe('MEDIATION', 'release', '2000017825798198')['label']);
        $this->assertSame(CashMovementConcept::DISPUTE, CashMovementConcept::fromLedger('RESERVE_FOR_BPP_SHIPPING_RETURN', 'release', '47724611357'));
        $this->assertNotSame(
            CashMovementConcept::SHIPPING_CREDIT,
            CashMovementConcept::fromLedger('RESERVE_FOR_BPP_SHIPPING_RETURN', 'release', '47724611357'),
        );
        $this->assertSame('Reserva por devolución', CashMovementConcept::describe('RESERVE_FOR_BPP_SHIPPING_RETURN', 'release', '47724611357')['label']);
        $this->assertSame(CashMovementConcept::PAYOUT_HOLD, CashMovementConcept::fromLedger('RESERVE_FOR_PAYOUT', 'release', null));
        $this->assertSame('Reserva para retiro', CashMovementConcept::describe('RESERVE_FOR_PAYOUT', 'release', null)['label']);
        $this->assertSame(
            CashMovementConcept::SHIPPING_DEBIT,
            CashMovementConcept::describe('SHIPPING', 'settlement', '47724611357', '-34.00')['key'],
        );
    }

    #[Test]
    public function never_treats_short_shipping_id_as_ml_order(): void
    {
        $this->assertTrue(CashMovementConcept::looksLikeMlOrderId('2000017840106932'));
        $this->assertFalse(CashMovementConcept::looksLikeMlOrderId('47724611357'));
        $this->assertTrue(CashMovementConcept::looksLikeShippingId('47724611357'));
        $this->assertFalse(CashMovementConcept::looksLikeShippingId('2000017840106932'));
        $this->assertSame('shipping', CashMovementConcept::referenceKind('47724611357', 'SHIPPING'));
        $this->assertSame('order', CashMovementConcept::referenceKind('2000017825798198', 'PAYMENT'));
        $this->assertSame('order', CashMovementConcept::referenceKind('2000017825798198', 'RESERVE_FOR_DISPUTE'));
    }
}
