<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Your password was changed" (SEC-06).
 *
 * A sibling of AccountErasureScheduled and PayoutAccountChanged, and it exists
 * for the same reason all three do: its entire value lies in reaching somebody
 * who did *not* do the thing it describes. The account holder who changed their
 * own password learns nothing from it. The one who did not learns that they
 * have minutes, not days.
 *
 * Sent to the details already on file rather than to anything supplied in the
 * request that changed the password — otherwise an attacker who has changed the
 * email as well would simply be notifying themselves.
 *
 * Urgent, so it ignores quiet hours and carries no unsubscribe. There is no
 * version of "do not disturb" that should outrank being told your account has
 * just been taken.
 */
class PasswordChanged extends AgentproNotification
{
    protected function preferredChannels(): array
    {
        return ['email', 'sms'];
    }

    protected function isUrgent(): bool
    {
        return true;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Agentpro password was changed')
            ->greeting('Hello '.$notifiable->name)
            ->line('The password on your Agentpro account was changed just now, and '
                  .'everywhere else you were signed in has been signed out.')
            ->line('If that was you, there is nothing to do.')
            ->line('**If it was not**, somebody else has your account. Reset your '
                  .'password immediately and contact '.config('agentpro.company.email').'.')
            ->action('Go to your account', route('password.edit'));
    }

    public function toSms(object $notifiable): string
    {
        return 'Agentpro: your password was just changed and other sessions were '
            .'signed out. If this was not you, contact us immediately.';
    }
}
