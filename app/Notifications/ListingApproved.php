<?php

namespace App\Notifications;

use App\Models\Property;
use Illuminate\Notifications\Messages\MailMessage;

class ListingApproved extends AgentproNotification
{
    public function __construct(public Property $property) {}

    protected function preferredChannels(): array
    {
        return ['email', 'push', 'whatsapp'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Your listing is live: '.$this->property->title)
            ->greeting('Good news, '.$notifiable->name)
            ->line($this->property->title.' has been approved and is now visible to seekers.')
            ->line('It will display until '.$this->property->expires_at?->format('j F Y').'.')
            ->action('View your listing', route('property.show', $this->property))
            ->line('Enquiries will arrive by the contact methods on the listing.');

        return $this->withUnsubscribe($mail, $notifiable);
    }

    public function toWhatsApp(object $notifiable): array
    {
        return [
            'template'  => 'listing_approved',
            'variables' => [
                'name'    => $notifiable->name,
                'listing' => $this->property->title,
            ],
        ];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'     => 'listing.approved',
            'title'    => 'Listing approved',
            'body'     => $this->property->title.' is now live.',
            'url'      => route('property.show', $this->property),
            'property' => $this->property->uuid,
        ];
    }
}
