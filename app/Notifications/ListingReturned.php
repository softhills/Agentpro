<?php

namespace App\Notifications;

use App\Models\Property;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A rejection (FR-M2-06).
 *
 * Carries the moderator's note rather than the reason code. The code exists for
 * reporting; the note is the only part a lister can act on, so it is the part
 * that travels.
 */
class ListingReturned extends AgentproNotification
{
    public function __construct(public Property $property, public string $note) {}

    protected function preferredChannels(): array
    {
        return ['email', 'push', 'sms'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Action needed on your listing: '.$this->property->title)
            ->greeting('Hello '.$notifiable->name)
            ->line('Your listing was reviewed and needs a change before it can go live.')
            ->line($this->note)
            ->action('Edit the listing', route('lister.listings.edit', $this->property))
            ->line('Resubmit whenever you are ready — there is no limit on attempts.');

        return $this->withUnsubscribe($mail, $notifiable);
    }

    /** The lister is blocked until they act, so this is worth a phone buzz. */
    public function toSms(object $notifiable): string
    {
        return 'Agentpro: "'.$this->property->title.'" needs changes before it can go live. '
            .$this->note.' Edit it at '.route('lister.listings.edit', $this->property);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'     => 'listing.returned',
            'title'    => 'Listing returned for changes',
            'body'     => $this->note,
            'url'      => route('lister.listings.edit', $this->property),
            'property' => $this->property->uuid,
        ];
    }
}
