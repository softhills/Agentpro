<?php

namespace App\Channels;

use App\Models\PushSubscription;
use App\Services\Push\WebPushSender;
use Illuminate\Notifications\Notification;

/**
 * Delivers to every browser the person has enabled push on.
 *
 * A notification opts in by implementing toPush(). Anything that does not is
 * simply not a push, and that is decided here rather than by each caller — the
 * same arrangement as WhatsApp.
 *
 * Fanning out across subscriptions is the whole job: someone with a phone and a
 * desktop expects both to light up, and someone whose old laptop is in a drawer
 * should stop costing a request every time.
 */
class WebPushChannel
{
    public function __construct(private WebPushSender $sender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toPush')) {
            return;
        }

        $subscriptions = PushSubscription::where('user_id', $notifiable->id ?? null)->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $payload = $notification->toPush($notifiable);

        foreach ($subscriptions as $subscription) {
            $delivery = $this->sender->send($subscription, $payload);

            if ($delivery->gone) {
                // The push service is authoritative about this: the browser has
                // been uninstalled, cleared, or has revoked permission. Keeping
                // the row would mean retrying it forever.
                $subscription->delete();

                continue;
            }

            if ($delivery->delivered) {
                $subscription->forceFill([
                    'last_used_at'  => now(),
                    'failure_count' => 0,
                ])->save();

                continue;
            }

            /*
             * A transient failure is not grounds for deleting a subscription —
             * a push service having a bad afternoon would otherwise unsubscribe
             * the entire user base. But a subscription that has failed for days
             * is dead in a way the service has not admitted to, so the count is
             * kept and the row is dropped once it is beyond doubt.
             */
            $subscription->forceFill([
                'last_failed_at' => now(),
                'failure_count'  => $subscription->failure_count + 1,
            ])->save();

            if ($subscription->failure_count >= (int) config('agentpro.push.give_up_after')) {
                $subscription->delete();
            }
        }
    }
}
