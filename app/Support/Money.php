<?php

namespace App\Support;

/**
 * Naira formatting (FR-M7-05).
 *
 * Figures in this market run long — a rent and a sale price differ by two orders
 * of magnitude and sit in the same results list. Thousands separators are not
 * decoration here; ₦4,500,000 and ₦450,000 are genuinely misread without them.
 */
final class Money
{
    public static function naira(float|int|string|null $amount, bool $compact = false): string
    {
        if ($amount === null) {
            return '—';
        }

        $amount = (float) $amount;

        if ($compact) {
            return '₦'.self::compact($amount);
        }

        return '₦'.number_format($amount, 0, '.', ',');
    }

    /** Map pins and dense chips: ₦7.5M, ₦950K, ₦1.2B. */
    public static function compact(float $amount): string
    {
        return match (true) {
            $amount >= 1_000_000_000 => self::trim($amount / 1_000_000_000).'B',
            $amount >= 1_000_000     => self::trim($amount / 1_000_000).'M',
            $amount >= 1_000         => self::trim($amount / 1_000).'K',
            default                  => (string) (int) $amount,
        };
    }

    private static function trim(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
    }
}
