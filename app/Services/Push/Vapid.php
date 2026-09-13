<?php

namespace App\Services\Push;

use App\Support\Base64Url;
use RuntimeException;

/**
 * Voluntary Application Server Identification — RFC 8292.
 *
 * A signed assertion that this push came from us. Push services use it to rate
 * limit and to reach an operator when something is wrong, and Firefox and
 * Chrome both reject unsigned requests outright.
 *
 * The audience is the *origin* of the endpoint, not the endpoint itself. Signing
 * the full URL produces a token every push service rejects, and the error says
 * only "unauthorized", so it is worth being explicit about.
 */
final class Vapid
{
    /**
     * Twelve hours. The spec allows twenty-four, and a token minted at the top
     * of a run would then expire mid-run on a slow queue; half the maximum
     * leaves room without making the token long-lived.
     */
    private const LIFETIME_SECONDS = 43200;

    public function __construct(
        private string $publicKey,       // raw 65-byte point
        private string $privateScalar,   // raw 32 bytes
        private string $subject,         // mailto: or https: an operator can be reached at
    ) {
        if ($this->subject === '' || ! preg_match('#^(mailto:|https://)#', $this->subject)) {
            throw new RuntimeException(
                'The VAPID subject must be a mailto: address or an https: URL a push service can use to contact you.'
            );
        }
    }

    public static function fromConfig(): self
    {
        $public  = (string) config('agentpro.push.public_key');
        $private = (string) config('agentpro.push.private_key');

        if ($public === '' || $private === '') {
            throw new RuntimeException(
                'VAPID keys are not configured. Generate them with: php artisan agentpro:push-keys'
            );
        }

        return new self(
            Base64Url::decode($public),
            Base64Url::decode($private),
            (string) config('agentpro.push.subject'),
        );
    }

    public static function isConfigured(): bool
    {
        return (string) config('agentpro.push.public_key') !== ''
            && (string) config('agentpro.push.private_key') !== '';
    }

    /** The key the browser needs in order to subscribe to us specifically. */
    public function publicKeyForBrowser(): string
    {
        return Base64Url::encode($this->publicKey);
    }

    /**
     * @return array<string,string> headers to add to the push request
     */
    public function headers(string $endpoint, ?int $now = null): array
    {
        $now ??= time();

        $header = ['typ' => 'JWT', 'alg' => 'ES256'];

        $claims = [
            'aud' => $this->audienceFor($endpoint),
            'exp' => $now + self::LIFETIME_SECONDS,
            'sub' => $this->subject,
        ];

        $signingInput = Base64Url::encode(json_encode($header, JSON_UNESCAPED_SLASHES))
            .'.'
            .Base64Url::encode(json_encode($claims, JSON_UNESCAPED_SLASHES));

        $key = P256::privateFromScalar($this->privateScalar, $this->publicKey);
        $signature = Base64Url::encode(P256::signJwt($key, $signingInput));

        return [
            'Authorization' => 'vapid t='.$signingInput.'.'.$signature.', k='.$this->publicKeyForBrowser(),
        ];
    }

    /** Scheme and host only — see the class note. */
    public function audienceFor(string $endpoint): string
    {
        $parts = parse_url($endpoint);

        if (empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException('That push endpoint is not a usable URL.');
        }

        $audience = $parts['scheme'].'://'.$parts['host'];

        // A non-default port is part of the origin; the default one must not be
        // written out, or the audience stops matching.
        if (isset($parts['port']) && ! in_array($parts['port'], [80, 443], true)) {
            $audience .= ':'.$parts['port'];
        }

        return $audience;
    }
}
