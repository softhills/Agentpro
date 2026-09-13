<?php

namespace App\Notifications;

use App\Models\ScanJob;
use Illuminate\Notifications\Messages\MailMessage;

class CaptureBooked extends AgentproNotification
{
    public function __construct(public ScanJob $job) {}

    protected function preferredChannels(): array
    {
        return ['email', 'push', 'whatsapp', 'sms'];
    }

    /** Somebody has to be there to let the technician in, so this cuts through. */
    protected function isUrgent(): bool
    {
        return true;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('3D capture booked for '.$this->job->scheduled_for->format('j F'))
            ->greeting('Hello '.$notifiable->name)
            ->line('Your 3D capture is booked for '.$this->job->scheduled_for->format('l j F Y, g:ia').'.')
            ->line($this->job->property->address_line.', '.$this->job->property->area?->name)
            ->line('Someone needs to be there to let the technician in. Expect about two hours.')
            ->action('View the booking', route('scan.schedule', $this->job->order));

        return $this->withUnsubscribe($mail, $notifiable);
    }

    public function toWhatsApp(object $notifiable): array
    {
        return [
            'template'  => 'capture_booked',
            'variables' => [
                'name' => $notifiable->name,
                'when' => $this->job->scheduled_for->format('l j F, g:ia'),
            ],
        ];
    }

    /**
     * The one notification that most earns an SMS: somebody has to be standing
     * at a gate on a particular morning, and a lister who is not online that
     * day still needs to know. No link — the date and the address are the
     * whole message, and a URL would cost half the allowance to repeat what is
     * already in the email.
     */
    public function toSms(object $notifiable): string
    {
        return 'Agentpro: your 3D capture is booked for '
            .$this->job->scheduled_for->format('D j M, g:ia').' at '
            .$this->job->property->address_line
            .'. Someone must be there to let the technician in (about 2 hours).';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'  => 'scan.booked',
            'title' => '3D capture booked',
            'body'  => $this->job->scheduled_for->format('l j F, g:ia').' at '.$this->job->property->address_line,
            'url'   => route('scan.schedule', $this->job->order),
        ];
    }
}
