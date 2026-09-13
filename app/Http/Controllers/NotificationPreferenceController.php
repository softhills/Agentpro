<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Support\Audit;
use App\Support\NotificationPreferences;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    public function edit(Request $request)
    {
        return view('account.notifications', [
            'preferences' => NotificationPreferences::for($request->user()),
            // Push is granted per browser, so the account-level switch above is
            // only half the story; this is the other half.
            'devices' => PushSubscription::where('user_id', $request->user()->id)
                ->orderByDesc('last_used_at')->orderByDesc('id')->get(),
            // Shown as we would dial it, and null when the stored number is not
            // one an SMS can reach — which is worth saying out loud rather than
            // leaving as a switch that silently does nothing.
            'smsNumber' => PhoneNumber::national($request->user()->phone),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'push'     => ['nullable', 'boolean'],
            'email'    => ['nullable', 'boolean'],
            'whatsapp' => ['nullable', 'boolean'],
            'sms'      => ['nullable', 'boolean'],
            'quiet_enabled'   => ['nullable', 'boolean'],
            'quiet_from'      => ['nullable', 'date_format:H:i'],
            'quiet_to'        => ['nullable', 'date_format:H:i'],
            'listing_updates' => ['nullable', 'boolean'],
            'saved_searches'  => ['nullable', 'boolean'],
        ]);

        $request->user()->update([
            'notification_preferences' => NotificationPreferences::fromForm($data),
        ]);

        return back()->with('status', 'Notification settings saved.');
    }

    /**
     * FR-M9-07: one tap, from any message, without signing in.
     *
     * Reached through a signed URL, so the link cannot be guessed or altered to
     * unsubscribe somebody else, and it works from an email client where no
     * session exists. Requiring a login to stop unwanted mail is how people end
     * up marking it as spam instead.
     */
    public function unsubscribe(Request $request, \App\Models\User $user, string $category)
    {
        abort_unless($request->hasValidSignature(), 403, 'This link has expired.');

        $preferences = NotificationPreferences::for($user)->toArray();

        if ($category === 'all') {
            foreach (NotificationPreferences::CHANNELS as $channel) {
                $preferences[$channel] = false;
            }
        } else {
            $preferences[$category] = false;
        }

        $user->update(['notification_preferences' => $preferences]);

        Audit::record('user.unsubscribed', $user, [], ['category' => $category], $user->id);

        return view('account.unsubscribed', ['category' => $category, 'user' => $user]);
    }
}
