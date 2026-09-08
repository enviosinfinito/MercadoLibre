<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Inventory\Support\FullStockOperationType;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FullStockOperationTypeTest extends TestCase
{
    #[Test]
    public function types_for_family_returns_null_for_all(): void
    {
        $this->assertNull(FullStockOperationType::typesForFamily('all'));
        $this->assertNull(FullStockOperationType::typesForFamily(''));
    }

    #[Test]
    public function types_for_family_maps_sale_and_inbound(): void
    {
        $this->assertSame(['SALE_CONFIRMATION'], FullStockOperationType::typesForFamily('sale'));
        $this->assertSame(['INBOUND_RECEPTION'], FullStockOperationType::typesForFamily('inbound'));
        $this->assertSame(['SALE_RETURN'], FullStockOperationType::typesForFamily('return'));
    }

    #[Test]
    public function types_for_family_other_is_empty_list(): void
    {
        $this->assertSame([], FullStockOperationType::typesForFamily('other'));
    }

    #[Test]
    public function family_for_type_covers_known_and_unknown(): void
    {
        $this->assertSame('inbound', FullStockOperationType::familyForType('INBOUND_RECEPTION'));
        $this->assertSame('sale', FullStockOperationType::familyForType('sale_confirmation'));
        $this->assertSame('cancellation', FullStockOperationType::familyForType('SALE_CANCELATION'));
        $this->assertSame('adjustment', FullStockOperationType::familyForType('AJUSTEMENT'));
        $this->assertSame('other', FullStockOperationType::familyForType('UNKNOWN_CUSTOM'));
    }

    #[Test]
    public function pill_variant_is_semantic(): void
    {
        $this->assertSame('success', FullStockOperationType::pillVariant('INBOUND_RECEPTION'));
        $this->assertSame('secondary', FullStockOperationType::pillVariant('SALE_CONFIRMATION'));
        $this->assertSame('warning', FullStockOperationType::pillVariant('SALE_RETURN'));
        $this->assertSame('danger', FullStockOperationType::pillVariant('SALE_CANCELATION'));
        $this->assertSame('muted', FullStockOperationType::pillVariant('ADJUSTMENT'));
    }
}
