<?php

namespace App\Support;

/**
 * What an SMS actually costs to send.
 *
 * SMS is billed per segment, not per message, and the segment boundary is not
 * where anyone expects it. A message of plain ASCII gets 160 characters; one
 * containing a single character outside the GSM 03.38 alphabet — a curly
 * apostrophe pasted from a document, an en dash, a ₦ sign — switches the whole
 * message to UCS-2 and the allowance drops to 70. Concatenation costs more
 * again: a two-part message is 2×153, not 2×160, because each part carries a
 * header.
 *
 * So a well-meant "₦7,500,000 — Ikoyi" is three segments where "N7,500,000 -
 * Ikoyi" is one, at triple the price for the same information. That is why this
 * class exists rather than a strlen() at the call site.
 */
final class SmsText
{
    /**
     * GSM 03.38 basic set.
     *
     * Single-quoted deliberately. The set contains '$¥', and in a double-quoted
     * string PHP reads that as a variable — multibyte characters are legal in
     * identifiers — which makes the whole thing a non-constant expression and
     * the class refuses to load.
     */
    private const GSM_BASIC = '@£$¥èéùìòÇ'."\n".'Øø'."\r"
        .'ÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?'
        .'¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';

    /** Characters that fit, but take two septets each. */
    private const GSM_EXTENDED = '^{}\\[~]|€';

    private const GSM_SINGLE = 160;

    private const GSM_CONCATENATED = 153;

    private const UCS2_SINGLE = 70;

    private const UCS2_CONCATENATED = 67;

    /** Can this be sent in the cheap 7-bit alphabet? */
    public static function isGsm7(string $text): bool
    {
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if (! str_contains(self::GSM_BASIC, $character) && ! str_contains(self::GSM_EXTENDED, $character)) {
                return false;
            }
        }

        return true;
    }

    /** Billable length: extended GSM characters count twice. */
    public static function length(string $text): int
    {
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (! self::isGsm7($text)) {
            return count($characters);
        }

        $length = 0;

        foreach ($characters as $character) {
            $length += str_contains(self::GSM_EXTENDED, $character) ? 2 : 1;
        }

        return $length;
    }

    /** How many messages the network will bill for. */
    public static function segments(string $text): int
    {
        $length = self::length($text);

        if ($length === 0) {
            return 0;
        }

        $gsm = self::isGsm7($text);
        $single = $gsm ? self::GSM_SINGLE : self::UCS2_SINGLE;

        if ($length <= $single) {
            return 1;
        }

        return (int) ceil($length / ($gsm ? self::GSM_CONCATENATED : self::UCS2_CONCATENATED));
    }

    /**
     * Replace the typographic characters that quietly triple the price.
     *
     * Applied to every outgoing message rather than left to whoever wrote the
     * copy: the curly quote comes from a paste, not a decision, and nobody
     * proof-reads an SMS template for its encoding.
     */
    public static function normalise(string $text): string
    {
        return strtr($text, [
            '‘' => "'", '’' => "'", '‚' => "'", '′' => "'",
            '“' => '"', '”' => '"', '„' => '"', '″' => '"',
            '–' => '-', '—' => '-', '−' => '-',
            '…' => '...',
            '·' => '.', '•' => '*',
            "\u{00A0}" => ' ',
            // The naira sign is the expensive one, and there is a conventional
            // ASCII stand-in every Nigerian reader recognises.
            '₦' => 'NGN ',
        ]);
    }

    /**
     * Trim to a segment budget without cutting a word in half.
     *
     * Returns the text unchanged when it already fits, so the common case costs
     * nothing.
     */
    public static function fit(string $text, int $maxSegments): string
    {
        if (self::segments($text) <= $maxSegments) {
            return $text;
        }

        $gsm = self::isGsm7($text);
        $budget = $maxSegments === 1
            ? ($gsm ? self::GSM_SINGLE : self::UCS2_SINGLE)
            : $maxSegments * ($gsm ? self::GSM_CONCATENATED : self::UCS2_CONCATENATED);

        // Three characters held back for the ellipsis, spelled with full stops
        // rather than the single "…" character: that one is outside GSM 03.38,
        // so adding it to trim a message would switch the whole thing to UCS-2
        // and make it longer than it was.
        $trimmed = mb_substr($text, 0, $budget - 3);
        $lastSpace = mb_strrpos($trimmed, ' ');

        if ($lastSpace !== false && $lastSpace > $budget * 0.6) {
            $trimmed = mb_substr($trimmed, 0, $lastSpace);
        }

        return rtrim($trimmed, " ,.;:-").'...';
    }
}
