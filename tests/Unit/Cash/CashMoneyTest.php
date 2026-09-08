<?php

namespace Tests\Unit\Cash;

use App\Domain\Cash\Support\CashMoney;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CashMoneyTest extends TestCase
{
    #[Test]
    public function status_from_diff_uses_tolerance(): void
    {
        $this->assertSame('balanced', CashMoney::statusFromDiff('0.005'));
        $this->assertSame('balanced', CashMoney::statusFromDiff('-0.009'));
        $this->assertSame('short', CashMoney::statusFromDiff('1.00'));
        $this->assertSame('over', CashMoney::statusFromDiff('-1.00'));
    }

    #[Test]
    public function arithmetic_is_exact_string_decimal(): void
    {
        $sum = CashMoney::add('10.10', '0.20');
        $this->assertSame('10.300000', $sum);
        $this->assertSame('0.100000', CashMoney::sub('10.20', '10.10'));
    }
}
