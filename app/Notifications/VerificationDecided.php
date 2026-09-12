<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class VerificationDecided extends AgentproNotification
{
    public function __construct(public string $state, public ?string $reason = null) {}

    protected function preferredChannels(): array
    {
        return ['email', 'push'];
    }

    /** Publishing is blocked until this lands, so it does not wait for morning. */
    protected function isUrgent(): bool
    {
        return true;
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->state === 'verified') {
            return $this->withUnsubscribe((new MailMessage)
                ->subject('You are verified')
                ->greeting('Hello '.$notifiable->name)
                ->line('Your identity has been verified. You can submit listings for review now.')
                ->action('Go to your listings', route('lister.dashboard')), $notifiable);
        }

        return $this->withUnsubscribe((new MailMessage)
            ->subject('We could not verify your details')
            ->greeting('Hello '.$notifiable->name)
            ->line($this->reason ?: 'The details did not match our records.')
            ->line('Check that the name on your account matches your document exactly, then try again.')
            ->action('Try again', route('verify.show')), $notifiable);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'  => 'verification.'.$this->state,
            'title' => $this->state === 'verified' ? 'Identity verified' : 'Verification unsuccessful',
            'body'  => $this->state === 'verified'
                ? 'You can submit listings for review.'
                : ($this->reason ?: 'The details did not match.'),
            'url'   => route('verify.show'),
        ];
    }
}
