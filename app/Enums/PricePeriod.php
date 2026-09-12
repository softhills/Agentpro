<?php

namespace App\Enums;

/**
 * FR-M7-06: a price without an explicit period is not a price.
 */
enum PricePeriod: string
{
    case Year  = 'year';
    case Month = 'month';
    case Night = 'night';
    case Once  = 'once';

    /** Suffix rendered next to the figure, e.g. ₦7,500,000/yr. */
    public function suffix(): string
    {
        return match ($this) {
            self::Year  => '/yr',
            self::Month => '/mo',
            self::Night => '/night',
            self::Once  => '',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Year  => 'per annum',
            self::Month => 'per month',
            self::Night => 'per night',
            self::Once  => 'total',
        };
    }
}
