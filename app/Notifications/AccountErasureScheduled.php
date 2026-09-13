<?php

namespace App\Notifications;

use App\Models\DataRequest;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Your account is scheduled to be erased" (FR-M1-09).
 *
 * The sibling of PayoutAccountChanged, and it exists for the same reason: its
 * whole value lies in reaching somebody who did *not* do the thing it
 * describes. Taking over an account is worth money if you can redirect a
 * payout; it is worth nothing but harm if all you can do is close the account —
 * which is precisely why a destroyed account is what a vindictive attacker
 * reaches for, and why this message matters as much as the payout one.
 *
 * It goes to the contact details already on file, ignores quiet hours, and
 * carries no unsubscribe of its own. An hour's sleep is not worth more than the
 * chance to stop your account being destroyed.
 */
class AccountErasureScheduled extends AgentproNotification
{
    public function __construct(public DataRequest $request) {}

    protected function preferredChannels(): array
    {
        return ['email', 'push', 'sms', 'whatsapp'];
    }

    protected function isUrgent(): bool
    {
        return true;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Your Agentpro account is scheduled to be closed')
            ->greeting('Hello '.$notifiable->name)
            ->line('Someone asked us to erase this account and everything on it.')
            ->line('It will happen on **'.$this->request->executes_at?->format('l j F, g:ia').'**, '
                  .'and after that we cannot bring any of it back.')
            ->line('**If this was not you, stop it now** — and change your password, because '
                  .'somebody else is signed in as you.')
            ->action('Stop this', route('privacy.index'));

        return $this->withUnsubscribe($mail, $notifiable);
    }

    public function toSms(object $notifiable): string
    {
        return 'Agentpro: your account is set to be permanently closed on '
            .$this->request->executes_at?->format('j M \a\t g:ia')
            .'. If this was not you, sign in and stop it now.';
    }

    public function toWhatsApp(object $notifiable): array
    {
        return [
            'template'  => 'account_erasure_scheduled',
            'variables' => [
                'name' => $notifiable->name,
                'when' => (string) $this->request->executes_at?->format('j M, g:ia'),
            ],
        ];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'  => 'privacy.erasure_scheduled',
            'title' => 'Your account is scheduled to be closed',
            'body'  => 'On '.$this->request->executes_at?->format('j M, g:ia')
                      .'. If this was not you, stop it now.',
            'url'   => route('privacy.index'),
        ];
    }
}
