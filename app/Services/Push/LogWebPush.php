<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;

/**
 * Development sender, used when no VAPID keys are configured.
 *
 * It still encrypts. That looks pointless for something that never leaves the
 * machine, and it is the entire value: encryption is where web push goes wrong,
 * the failure is invisible (the push service returns 201 and the user simply
 * never sees anything), and a development driver that skipped it would let a
 * broken key schedule reach production unchallenged.
 *
 * `publicKey()` returns null, which is what makes the browser-side subscribe
 * button correctly say push is not available here rather than failing on tap.
 */
class LogWebPush implements WebPushSender
{
    public function publicKey(): ?string
    {
        return null;
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
            Log::error('push.encrypt_failed', [
                'subscription' => $subscription->id,
                'message'      => $e->getMessage(),
            ]);

            return PushDelivery::failed($e->getMessage());
        }

        Log::info('push.would_send', [
            'endpoint'   => \Illuminate\Support\Str::limit($subscription->endpoint, 60),
            'title'      => $payload['title'] ?? null,
            'body_bytes' => strlen($body),
        ]);

        return PushDelivery::sent();
    }
}
