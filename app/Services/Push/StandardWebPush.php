<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Web push over HTTP — RFC 8030, with RFC 8291 bodies and RFC 8292 auth.
 *
 * There is no vendor here and no account to open: the endpoint the browser
 * hands us already names its own push service, and the same request works
 * against Google, Mozilla and Microsoft. That is unusual enough to be worth
 * saying, because it means push costs nothing to run, which is the argument for
 * preferring it over SMS wherever it will do.
 */
class StandardWebPush implements WebPushSender
{
    public function __construct(private Vapid $vapid) {}

    public function publicKey(): ?string
    {
        return $this->vapid->publicKeyForBrowser();
    }

    public function send(PushSubscription $subscription, array $payload): PushDelivery
    {
        try {
            $body = PushPayload::encrypt(
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                PushPayload::keyFrom($subscription->p256dh),
                PushPayload::keyFrom($subscription->auth),
            );
        } catch (\Throwable $e) {
            // Encryption failing is a bad subscription, not a transient fault,
            // so it must not be retried. Logged loudly because it is either
            // corrupt stored key material or a bug in the key schedule, and
            // both are worth finding rather than absorbing.
            Log::error('push.encrypt_failed', [
                'subscription' => $subscription->id,
                'message'      => $e->getMessage(),
            ]);

            return PushDelivery::failed($e->getMessage());
        }

        try {
            $response = Http::withHeaders($this->vapid->headers($subscription->endpoint) + [
                'Content-Type'     => 'application/octet-stream',
                'Content-Encoding' => 'aes128gcm',
                'TTL'              => (string) config('agentpro.push.ttl'),
                // Everything this application sends is worth waking a screen
                // for; nothing is background telemetry.
                'Urgency'          => 'normal',
            ])
                ->withBody($body, 'application/octet-stream')
                ->timeout(10)
                ->post($subscription->endpoint);
        } catch (\Throwable $e) {
            return PushDelivery::failed($e->getMessage());
        }

        // 404 and 410 are the push service saying this browser is gone. Anything
        // else may be transient and the subscription is kept.
        if (in_array($response->status(), [404, 410], true)) {
            return PushDelivery::expired($response->status());
        }

        if ($response->successful()) {
            return PushDelivery::sent($response->status());
        }

        Log::warning('push.rejected', [
            'subscription' => $subscription->id,
            'status'       => $response->status(),
            'body'         => \Illuminate\Support\Str::limit($response->body(), 200),
        ]);

        return PushDelivery::failed(
            'The push service returned '.$response->status().'.',
            $response->status(),
        );
    }
}
