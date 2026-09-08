<?php

namespace Tests\Unit;

use App\Domain\Shared\ValueObjects\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    #[Test]
    public function it_adds_same_currency_amounts(): void
    {
        $a = new Money('10.50', 'MXN');
        $b = new Money('1.25', 'MXN');

        $this->assertTrue($a->add($b)->equals(new Money('11.75', 'MXN')));
    }

    #[Test]
    public function it_subtracts_same_currency_amounts(): void
    {
        $a = new Money('10.00', 'USD');
        $b = new Money('3.33', 'USD');

        $this->assertTrue($a->subtract($b)->equals(new Money('6.67', 'USD')));
    }

    #[Test]
    public function it_rejects_cross_currency_math(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Money('1', 'MXN'))->add(new Money('1', 'USD'));
    }

    #[Test]
    public function it_rejects_invalid_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Money('1.2.3', 'MXN');
    }

    #[Test]
    public function it_stringifies(): void
    {
        $this->assertSame('12.00 MXN', (string) new Money('12.00', 'MXN'));
    }
}
