<?php

namespace App\Support;

/**
 * Nigerian numbers, normalised to E.164.
 *
 * People type their number the way they say it — 0803 123 4567 — and every SMS
 * gateway wants 2348031234567. Doing that conversion at the point of sending,
 * once, is the difference between a message delivered and a silent gateway
 * rejection that costs money either way.
 *
 * Deliberately narrow: this knows Nigeria, because that is where the product
 * operates (PRD Q17). A number that is not recognisably Nigerian is returned as
 * null rather than guessed at — sending an SMS to a number we mangled is worse
 * than not sending one.
 */
final class PhoneNumber
{
    private const COUNTRY = '234';

    /**
     * Nigerian mobile prefixes, without the trunk zero.
     *
     * 70, 80, 81, 90, 91 cover MTN, Glo, Airtel and 9mobile. Landlines and
     * short codes are excluded on purpose: an SMS to one is a charge with no
     * chance of delivery.
     */
    private const MOBILE_PREFIXES = ['70', '71', '80', '81', '90', '91'];

    /** @return string|null E.164 with a leading +, or null if it is not usable */
    public static function e164(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $input) ?? '';

        // +234..., 234...
        if (str_starts_with($digits, self::COUNTRY)) {
            $national = substr($digits, strlen(self::COUNTRY));
        } elseif (str_starts_with($digits, '0')) {
            // 0803...
            $national = substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            // 803..., typed without the trunk zero
            $national = $digits;
        } else {
            return null;
        }

        if (strlen($national) !== 10) {
            return null;
        }

        if (! in_array(substr($national, 0, 2), self::MOBILE_PREFIXES, true)) {
            return null;
        }

        return '+'.self::COUNTRY.$national;
    }

    /** Digits only, which is the form most gateways actually want in the body. */
    public static function msisdn(?string $input): ?string
    {
        $e164 = self::e164($input);

        return $e164 === null ? null : ltrim($e164, '+');
    }

    /** 0803 123 4567 — how a Nigerian reader expects to see their own number. */
    public static function national(?string $input): ?string
    {
        $e164 = self::e164($input);

        if ($e164 === null) {
            return null;
        }

        $national = substr($e164, 1 + strlen(self::COUNTRY));

        return '0'.substr($national, 0, 3).' '.substr($national, 3, 3).' '.substr($national, 6);
    }
}
