<?php

namespace App\Domain\Shared\ValueObjects;

use InvalidArgumentException;
use Stringable;

readonly class Money implements Stringable
{
    public function __construct(
        public string $amount,
        public string $currencyCode,
    ) {
        if (! preg_match('/^-?\d+(\.\d+)?$/', $this->amount)) {
            throw new InvalidArgumentException('Money amount must be a decimal string.');
        }

        if ($this->currencyCode === '' || strlen($this->currencyCode) !== 3) {
            throw new InvalidArgumentException('currency_code must be a 3-letter ISO code.');
        }
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(
            bcadd($this->amount, $other->amount, $this->scale()),
            $this->currencyCode,
        );
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(
            bcsub($this->amount, $other->amount, $this->scale()),
            $this->currencyCode,
        );
    }

    public function equals(self $other): bool
    {
        return $this->currencyCode === $other->currencyCode
            && bccomp($this->amount, $other->amount, $this->scale()) === 0;
    }

    public function __toString(): string
    {
        return $this->amount.' '.$this->currencyCode;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currencyCode !== $other->currencyCode) {
            throw new InvalidArgumentException('Cannot operate on Money with different currencies.');
        }
    }

    private function scale(): int
    {
        return max($this->decimalPlaces($this->amount), 8);
    }

    private function decimalPlaces(string $amount): int
    {
        $pos = strpos($amount, '.');

        return $pos === false ? 0 : strlen($amount) - $pos - 1;
    }
}
