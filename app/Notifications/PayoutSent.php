<?php

namespace App\Notifications;

use App\Models\Payout;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;

/** Money has reached the lister's bank (FR-M11-07). */
class PayoutSent extends AgentproNotification
{
    public function __construct(public Payout $payout) {}

    protected function preferredChannels(): array
    {
        return ['email', 'push', 'sms'];
    }

    /** A receipt held back looks like a payment that did not arrive. */
    protected function isUrgent(): bool
    {
        return true;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(Money::naira($this->payout->amount).' paid to your account')
            ->greeting('Hello '.$notifiable->name)
            ->line(Money::naira($this->payout->amount).' has been sent to '
                .$this->payout->account->bank_name.' '.$this->payout->account->masked().'.')
            ->line('Banks can take a few hours to show it.')
            ->action('See your payout history', route('lister.payouts'));

        return $this->withUnsubscribe($mail, $notifiable);
    }

    public function toSms(object $notifiable): string
    {
        return 'Agentpro: '.Money::naira($this->payout->amount).' has been paid to your '
            .$this->payout->account->bank_name.' account ending '
            .substr($this->payout->account->account_number, -4).'.';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'  => 'payout.sent',
            'title' => Money::naira($this->payout->amount).' paid out',
            'body'  => 'Sent to '.$this->payout->account->bank_name.' '.$this->payout->account->masked().'.',
            'url'   => route('lister.payouts'),
        ];
    }
}
