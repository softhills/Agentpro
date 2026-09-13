<?php

namespace App\Services\Push;

use App\Support\Base64Url;
use RuntimeException;

/**
 * Message encryption for web push — RFC 8291 over RFC 8188 aes128gcm.
 *
 * The push service is an untrusted relay: Google, Mozilla and Microsoft all
 * forward the body without being able to read it, and only the browser that
 * created the subscription holds the key. That is the point of the scheme, and
 * it is why this cannot be skipped or simplified — an unencrypted body is
 * simply rejected.
 *
 * The key schedule, in order, because reading it out of the code is harder than
 * reading it here:
 *
 *   1. ECDH between a throwaway key pair of ours and the browser's public key.
 *   2. That secret is combined with the subscription's auth secret and both
 *      public keys, which is what binds the message to this one subscription.
 *   3. A random salt turns the result into a content key and a nonce.
 *
 * Every step is HKDF-SHA256, which PHP has natively.
 */
final class PushPayload
{
    /**
     * The record size the body declares.
     *
     * 4096 is what every browser guarantees to accept. Larger records are legal
     * and unreliable, and a push that is silently dropped by one browser vendor
     * is the worst kind of bug to own.
     */
    public const RECORD_SIZE = 4096;

    /**
     * Ceiling on the plaintext, leaving room for the header, the padding
     * delimiter and the GCM tag. Notifications are a headline and a link, so
     * anything near this is a design mistake rather than a limit to raise.
     */
    public const MAX_PLAINTEXT = 3800;

    /**
     * @param  string  $plaintext  the JSON the service worker will read
     * @param  string  $uaPublic  65-byte point from the subscription (p256dh)
     * @param  string  $authSecret  16-byte subscription auth secret
     * @param  string|null  $salt  overridable only so the RFC's own test vector can be reproduced
     * @param  array{private: \OpenSSLAsymmetricKey, public: string}|null  $ephemeral  likewise
     */
    public static function encrypt(
        string $plaintext,
        string $uaPublic,
        string $authSecret,
        ?string $salt = null,
        ?array $ephemeral = null,
    ): string {
        if (strlen($plaintext) > self::MAX_PLAINTEXT) {
            throw new RuntimeException(
                'Push payload is '.strlen($plaintext).' bytes; the limit is '.self::MAX_PLAINTEXT.'.'
            );
        }

        if (strlen($authSecret) !== 16) {
            throw new RuntimeException('A subscription auth secret is 16 bytes; got '.strlen($authSecret).'.');
        }

        $salt ??= random_bytes(16);
        $ephemeral ??= P256::generate();

        $sharedSecret = P256::sharedSecret($ephemeral['private'], $uaPublic);

        /*
         * RFC 8291 §3.4. Both public keys go into the info string, in the order
         * receiver-then-sender. Swapping them produces a key that is perfectly
         * valid and that the browser cannot derive — a failure that shows up as
         * a push the user never sees, with a 201 from the push service.
         */
        $ikm = hash_hkdf(
            'sha256',
            $sharedSecret,
            32,
            "WebPush: info\0".$uaPublic.$ephemeral['public'],
            $authSecret,
        );

        $contentKey = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce      = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        // 0x02 marks the last record. One record is always the last one here.
        $padded = $plaintext."\x02";

        $tag = null;
        $ciphertext = openssl_encrypt(
            $padded,
            'aes-128-gcm',
            $contentKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('AES-128-GCM encryption failed.');
        }

        /*
         * RFC 8188 §2.1 header: salt, record size, key id length, key id. The
         * key id here is our ephemeral public key, which is how the browser
         * knows what to run ECDH against.
         */
        return $salt
            .pack('N', self::RECORD_SIZE)
            .chr(65)
            .$ephemeral['public']
            .$ciphertext
            .$tag;
    }

    /**
     * The receiving half, from the browser's point of view.
     *
     * Not used in production — nothing here receives a push. It exists so the
     * encryption can be checked against the RFC's published test vector from
     * both directions, which is the only way to be sure the key schedule is
     * right without a real browser on the other end.
     */
    public static function decrypt(string $body, string $uaPrivateScalar, string $uaPublic, string $authSecret): string
    {
        $salt = substr($body, 0, 16);
        $keyIdLength = ord($body[20]);
        $senderPublic = substr($body, 21, $keyIdLength);
        $ciphertext = substr($body, 21 + $keyIdLength);

        $private = P256::privateFromScalar($uaPrivateScalar, $uaPublic);
        $sharedSecret = P256::sharedSecret($private, $senderPublic);

        $ikm = hash_hkdf('sha256', $sharedSecret, 32, "WebPush: info\0".$uaPublic.$senderPublic, $authSecret);

        $contentKey = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce      = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $tag = substr($ciphertext, -16);
        $sealed = substr($ciphertext, 0, -16);

        $plain = openssl_decrypt($sealed, 'aes-128-gcm', $contentKey, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($plain === false) {
            throw new RuntimeException('Could not decrypt the push body.');
        }

        return rtrim($plain, "\x02\x00");
    }

    /** Convenience for reading a subscription's base64url key material. */
    public static function keyFrom(string $encoded): string
    {
        return Base64Url::decode($encoded);
    }
}
