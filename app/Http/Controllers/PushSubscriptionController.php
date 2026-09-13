<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\Push\WebPushSender;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Registering a browser for push (FR-M9-08).
 *
 * The browser does the hard part — it asks the user, talks to its own push
 * service and mints an endpoint. All this does is remember the result against
 * the signed-in account.
 */
class PushSubscriptionController extends Controller
{
    /**
     * What the client needs before it can subscribe.
     *
     * Served from an endpoint rather than embedded in every page: it is only
     * wanted at the moment somebody taps "enable", and a null answer is the
     * honest way to say push is not configured on this deployment.
     */
    public function key(WebPushSender $sender)
    {
        return response()->json(['key' => $sender->publicKey()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'endpoint'      => ['required', 'url', 'max:2000'],
            'keys.p256dh'   => ['required', 'string', 'max:128'],
            'keys.auth'     => ['required', 'string', 'max:48'],
        ], [
            'endpoint.url' => 'That is not a valid push endpoint.',
        ]);

        /*
         * Upserted on the endpoint hash, not created.
         *
         * A browser hands back the same endpoint every time it is asked until
         * the subscription is revoked, and a page that re-registers on every
         * load — which is the recommended client behaviour — would otherwise
         * accumulate rows and deliver the same notification several times over.
         *
         * The user_id is part of the update, so a shared computer moves the
         * subscription to whoever signed in last rather than leaving the
         * previous person's notifications arriving on it.
         */
        PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashFor($data['endpoint'])],
            [
                'user_id'      => $request->user()->id,
                'endpoint'     => $data['endpoint'],
                'p256dh'       => $data['keys']['p256dh'],
                'auth'         => $data['keys']['auth'],
                'user_agent'   => Str::limit((string) $request->userAgent(), 250, ''),
                'last_used_at' => now(),
                'failure_count' => 0,
            ]
        );

        return response()->json(['subscribed' => true]);
    }

    public function destroy(Request $request)
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000'],
        ]);

        // Scoped to the signed-in user: an endpoint is not a secret, and
        // without this anyone could unsubscribe anyone whose endpoint they knew.
        PushSubscription::where('endpoint_hash', PushSubscription::hashFor($data['endpoint']))
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['subscribed' => false]);
    }

    /** Drop a device from the settings page, without being on that device. */
    public function forget(Request $request, PushSubscription $subscription)
    {
        abort_unless($subscription->user_id === $request->user()->id, 404);

        $subscription->delete();

        return back()->with('status', 'That device will no longer receive push notifications.');
    }
}
