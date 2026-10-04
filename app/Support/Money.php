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

    /**
     * The same figure as it should sit *inside a form field*: grouped, but with
     * no currency symbol — the label already carries the ₦, and a symbol in the
     * box is something the user has to type around.
     *
     * Kobo appear only when there are any. A price is quoted in whole naira in
     * this market, so showing "4,500,000.00" in the box invites someone to
     * delete the ".00" before typing, and padding every figure with decimals
     * that mean nothing is how a price field starts to look like an invoice.
     *
     * Anything that is not a number is handed straight back. That is the path
     * a rejected submission takes: the validator refused "4,5OO,OOO" (with the
     * letter O), and the lister has to see what they actually typed to fix it.
     * Coercing it to 0 here would replace their mistake with a plausible,
     * wrong price.
     */
    public static function field(float|int|string|null $amount): string
    {
        if ($amount === null || $amount === '' || ! is_numeric($amount)) {
            return (string) $amount;
        }

        $amount = (float) $amount;

        return number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2, '.', ',');
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
