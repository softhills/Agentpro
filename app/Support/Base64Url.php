<?php

namespace App\Support;

/**
 * Base64url without padding (RFC 4648 §5).
 *
 * Every value that crosses the web-push boundary — VAPID keys, subscription
 * keys, JWT segments — is encoded this way, and the browser's PushManager emits
 * it unpadded. Decoding has to tolerate the missing padding rather than
 * rejecting it, which is the whole reason this is not just two inline calls.
 */
final class Base64Url
{
    public static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function decode(string $encoded): string
    {
        $padded = strtr($encoded, '-_', '+/');

        return (string) base64_decode(str_pad($padded, (int) (ceil(strlen($padded) / 4) * 4), '=', STR_PAD_RIGHT), true);
    }
}
