<?php

namespace App\Domain\Cash\Support;

/**
 * Align expected vs collections settlement buckets (fee / shipping / tax residual).
 */
final class CashSettlementBuckets
{
    /**
     * @param  list<mixed>|null  $feeLines  profit_snapshot breakdown.fees
     */
    public static function expectedFeeExcludingShipping(?array $feeLines): ?string
    {
        return self::sumFeeLines($feeLines, includeShipping: false);
    }

    /**
     * @param  list<mixed>|null  $feeLines
     */
    public static function expectedShippingFromFees(?array $feeLines): ?string
    {
        return self::sumFeeLines($feeLines, includeShipping: true, onlyShipping: true);
    }

    /**
     * Prefer collection shipping when present and > 0; otherwise fall back to expected seller shipping.
     */
    public static function resolveShippingCost(?string $collectionShipping, ?string $expectedShipping): ?string
    {
        $fromCollection = CashMoney::scale($collectionShipping);
        if ($fromCollection !== null && CashMoney::cmp($fromCollection, '0') === 1) {
            return $fromCollection;
        }

        $fromExpected = CashMoney::scale($expectedShipping);
        if ($fromExpected !== null && CashMoney::cmp($fromExpected, '0') === 1) {
            return $fromExpected;
        }

        return $fromCollection ?? $fromExpected;
    }

    /**
     * tax = transaction − fee − shipping − net (floored at 0).
     */
    public static function residualTax(
        ?string $transaction,
        ?string $fee,
        ?string $shipping,
        ?string $net,
    ): ?string {
        if ($transaction === null || $fee === null || $net === null) {
            return null;
        }

        $tax = CashMoney::sub($transaction, $fee);
        if ($shipping !== null) {
            $tax = CashMoney::sub($tax, $shipping);
        }
        $tax = CashMoney::sub($tax, $net);
        if (CashMoney::cmp($tax, '0') === -1) {
            return CashMoney::zero();
        }

        return $tax;
    }

    /**
     * @param  list<mixed>|null  $feeLines
     */
    private static function sumFeeLines(?array $feeLines, bool $includeShipping, bool $onlyShipping = false): ?string
    {
        if ($feeLines === null || $feeLines === []) {
            return null;
        }

        $total = CashMoney::zero();
        $any = false;
        foreach ($feeLines as $line) {
            if (! is_array($line) || ! isset($line['amount']) || ! is_numeric($line['amount'])) {
                continue;
            }
            $type = (string) ($line['event_type'] ?? '');
            $isShipping = str_contains($type, 'shipping');
            if ($onlyShipping && ! $isShipping) {
                continue;
            }
            if (! $onlyShipping && ! $includeShipping && $isShipping) {
                continue;
            }
            $any = true;
            $total = CashMoney::add($total, CashMoney::scale((string) $line['amount']) ?? '0');
        }

        return $any ? $total : null;
    }
}
