<?php

namespace App\Notifications;

use App\Models\Property;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Your listing was verified" — or "the badge has been removed" (FR-M6-01).
 *
 * One notification for both directions on purpose. They are the same event
 * from the lister's side — the trust mark on their listing changed — and
 * splitting them into two classes would let the copy drift until the good news
 * was warm and the bad news was a terse line nobody wrote carefully.
 *
 * The removal is urgent and the grant is not. A badge appearing is welcome
 * whenever it arrives; a badge disappearing from a live listing is something
 * the lister will be asked about by the next person who calls, and they should
 * hear it from us first.
 */
class RealsureDecided extends AgentproNotification
{
    public function __construct(
        public Property $property,
        public bool $granted,
        public ?string $reason = null,
    ) {}

    protected function preferredChannels(): array
    {
        return $this->granted ? ['email', 'push'] : ['email', 'push', 'whatsapp'];
    }

    protected function isUrgent(): bool
    {
        return ! $this->granted;
    }

    /**
     * No category, so this carries no one-tap unsubscribe.
     *
     * Being able to switch off the message that says the trust mark came off
     * your live listing is not a preference worth honouring — the lister is
     * still selling against it either way.
     */
    protected function category(): ?string
    {
        return null;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->greeting('Hello '.$notifiable->name);

        if ($this->granted) {
            $mail->subject('RealSure verified: '.$this->property->title)
                ->line('**'.$this->property->title.'** now carries the RealSure badge.')
                ->line('Seekers can see exactly which checks were completed and when, which is '
                      .'what makes the badge worth having.')
                ->action('See the listing', route('property.show', $this->property));
        } else {
            $mail->subject('RealSure badge removed: '.$this->property->title)
                ->line('The RealSure badge has been removed from **'.$this->property->title.'**.')
                ->line('**Why:** '.$this->reason)
                ->line('The listing is still live and nothing else about it has changed. If you '
                      .'think this is wrong, or you want to discuss the fee, reply to this message.')
                ->action('See the listing', route('property.show', $this->property));
        }

        return $this->withUnsubscribe($mail, $notifiable);
    }

    public function toWhatsApp(object $notifiable): array
    {
        return [
            'template'  => 'realsure_badge_removed',
            'variables' => [
                'name'    => $notifiable->name,
                'listing' => $this->property->title,
                'reason'  => (string) $this->reason,
            ],
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->granted
            ? [
                'type'  => 'realsure.granted',
                'title' => 'RealSure verified',
                'body'  => $this->property->title.' now carries the badge.',
                'url'   => route('property.show', $this->property),
            ]
            : [
                'type'  => 'realsure.revoked',
                'title' => 'RealSure badge removed',
                'body'  => $this->property->title.' — '.$this->reason,
                'url'   => route('property.show', $this->property),
            ];
    }
}
