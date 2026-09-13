<?php

namespace App\Notifications;

use App\Models\PayoutAccount;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Your bank details were changed" (FR-M11-07).
 *
 * The most important message the platform sends, and the only one whose value
 * lies entirely in reaching somebody who did *not* do the thing it describes.
 * It goes to the contact details already on file, never to anything supplied
 * with the change, and it ignores quiet hours: an attacker who changes bank
 * details at 2am is exactly the case a held-back alert would cover for.
 *
 * It carries no unsubscribe of its own — `category()` stays null, so the base
 * class offers settings rather than a one-tap off switch. Being able to turn
 * off the warning that your money is being redirected is not a preference worth
 * honouring.
 */
class PayoutAccountChanged extends AgentproNotification
{
    public function __construct(public PayoutAccount $account) {}

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
            ->subject('Your Agentpro payout account was changed')
            ->greeting('Hello '.$notifiable->name)
            ->line('The bank account for your Agentpro payouts was changed to:')
            ->line('**'.$this->account->account_name.'** · '.$this->account->bank_name.' · '.$this->account->masked())
            ->line(
                'Nothing can be sent to it until '
                .$this->account->usable_from?->format('j F, g:ia')
                .', which is a security hold rather than a review.'
            )
            ->line('**If this was not you, tell us now** — your account may have been taken over.')
            ->action('Review your payout details', route('lister.payouts'));

        return $this->withUnsubscribe($mail, $notifiable);
    }

    public function toSms(object $notifiable): string
    {
        return 'Agentpro: your payout bank account was changed to '
            .$this->account->bank_name.' '.substr($this->account->account_number, -4)
            .'. If this was not you, contact us immediately.';
    }

    public function toWhatsApp(object $notifiable): array
    {
        return [
            'template'  => 'payout_account_changed',
            'variables' => [
                'name' => $notifiable->name,
                'bank' => $this->account->bank_name,
                'last4' => substr($this->account->account_number, -4),
            ],
        ];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'  => 'payout.account_changed',
            'title' => 'Payout account changed',
            'body'  => $this->account->bank_name.' '.$this->account->masked().'. If this was not you, tell us now.',
            'url'   => route('lister.payouts'),
        ];
    }
}
