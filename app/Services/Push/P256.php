<?php

namespace App\Services\Push;

use RuntimeException;

/**
 * The NIST P-256 operations web push needs, using only PHP's own OpenSSL
 * bindings.
 *
 * Deliberately no package. The obvious dependency (minishlink/web-push) wants
 * ext-gmp, which is not in a stock XAMPP or a default PHP build, and adding an
 * extension requirement to the deployment is exactly the cost this project was
 * set up to avoid. Everything below is in core PHP 8: openssl for the curve and
 * AES-GCM, hash_hkdf for the key schedule.
 *
 * Keys cross the wire as raw values, not PEM — a browser's PushManager gives a
 * 65-byte uncompressed point, and the VAPID header carries one too. OpenSSL
 * will not take those directly, so the conversions live here.
 */
final class P256
{
    /**
     * DER prefix for a P-256 SubjectPublicKeyInfo.
     *
     * Fixed for this curve, so the only variable part is the point that follows
     * it. Assembling the structure by hand is what lets a raw browser key be
     * handed to OpenSSL at all.
     */
    private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** RFC 5915 ECPrivateKey, around a 32-byte scalar and its 65-byte point. */
    private const EC_KEY_PREFIX = '30770201010420';

    private const EC_KEY_MIDDLE = 'a00a06082a8648ce3d030107a144034200';

    /** @return array{private: \OpenSSLAsymmetricKey, public: string} */
    public static function generate(): array
    {
        $key = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($key === false) {
            throw new RuntimeException('OpenSSL could not generate a P-256 key pair.');
        }

        return ['private' => $key, 'public' => self::pointOf($key)];
    }

    /** The uncompressed point (0x04 || X || Y) for a key pair. */
    public static function pointOf(\OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);

        // Left-padded to 32 bytes each: OpenSSL strips leading zero bytes, and
        // a 31-byte coordinate produces a point every push service rejects.
        return "\x04"
            .str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
            .str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    /** The 32-byte private scalar, for storing a VAPID key in configuration. */
    public static function scalarOf(\OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);

        return str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT);
    }

    /**
     * Rebuild a usable key from the stored scalar and its own public point.
     *
     * Both halves are required, and that is not an inconvenience worth
     * engineering around: PHP offers no way to recover a point from a scalar,
     * and the public key has to be published to browsers anyway, so it is
     * already in configuration. Carrying a PEM in the environment instead would
     * make the key a multi-line value that .env parsers handle differently.
     */
    public static function privateFromScalar(string $scalar, string $point): \OpenSSLAsymmetricKey
    {
        if (strlen($scalar) !== 32) {
            throw new RuntimeException('A P-256 private key is 32 bytes; got '.strlen($scalar).'.');
        }

        self::assertPoint($point);

        $der = hex2bin(self::EC_KEY_PREFIX).$scalar.hex2bin(self::EC_KEY_MIDDLE).$point;

        $key = openssl_pkey_get_private(
            "-----BEGIN EC PRIVATE KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END EC PRIVATE KEY-----\n"
        );

        if ($key === false) {
            throw new RuntimeException(
                'The configured VAPID key pair is not a valid P-256 key. '
                .'Regenerate it with: php artisan agentpro:push-keys'
            );
        }

        return $key;
    }

    /**
     * Shared secret from our private key and their raw public point.
     *
     * @param  string  $peerPoint  65-byte uncompressed point
     */
    public static function sharedSecret(\OpenSSLAsymmetricKey $private, string $peerPoint): string
    {
        self::assertPoint($peerPoint);

        $peer = openssl_pkey_get_public(self::publicPem($peerPoint));

        if ($peer === false) {
            throw new RuntimeException('The subscription public key is not a valid P-256 point.');
        }

        $secret = openssl_pkey_derive($peer, $private, 32);

        if ($secret === false) {
            throw new RuntimeException('ECDH derivation failed.');
        }

        return $secret;
    }

    /** ES256 over the message, as the raw 64-byte R||S a JWT requires. */
    public static function signJwt(\OpenSSLAsymmetricKey $private, string $message): string
    {
        $der = null;

        if (! openssl_sign($message, $der, $private, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the VAPID token.');
        }

        return self::derToRaw($der);
    }

    public static function publicPem(string $point): string
    {
        self::assertPoint($point);

        $der = hex2bin(self::SPKI_PREFIX).$point;

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    private static function assertPoint(string $point): void
    {
        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new RuntimeException(
                'Expected a 65-byte uncompressed P-256 point, got '.strlen($point).' bytes.'
            );
        }
    }

    /**
     * OpenSSL emits ECDSA signatures as DER SEQUENCE{INTEGER r, INTEGER s};
     * JWS wants the two integers fixed-width and concatenated. The lengths vary
     * — DER drops leading zeros and adds one back when the high bit is set — so
     * this cannot be a substring.
     */
    private static function derToRaw(string $der): string
    {
        if (($der[0] ?? '') !== "\x30") {
            throw new RuntimeException('Malformed DER signature from OpenSSL: no SEQUENCE.');
        }

        // Past the SEQUENCE tag and its length, into the body — not over it.
        // A P-256 signature is always short-form, but stepping over the two
        // INTEGERs instead of into them is the mistake worth guarding against.
        $offset = 2;

        $integer = function () use ($der, &$offset): string {
            if (($der[$offset] ?? '') !== "\x02") {
                throw new RuntimeException('Malformed DER signature from OpenSSL: expected INTEGER.');
            }

            $offset++;
            $length = ord($der[$offset++]);
            $value = substr($der, $offset, $length);
            $offset += $length;

            return $value;
        };

        $r = ltrim($integer(), "\0");
        $s = ltrim($integer(), "\0");

        return str_pad($r, 32, "\0", STR_PAD_LEFT).str_pad($s, 32, "\0", STR_PAD_LEFT);
    }
}
