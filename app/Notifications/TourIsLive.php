<?php

namespace App\Notifications;

use App\Models\Property;
use Illuminate\Notifications\Messages\MailMessage;

class TourIsLive extends AgentproNotification
{
    public function __construct(public Property $property) {}

    protected function preferredChannels(): array
    {
        return ['email', 'push'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Your 3D tour is live')
            ->greeting('Hello '.$notifiable->name)
            ->line('The 3D tour for '.$this->property->title.' has finished processing and is on your listing.')
            ->action('See the tour', route('property.show', $this->property));

        return $this->withUnsubscribe($mail, $notifiable);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'  => 'scan.live',
            'title' => '3D tour is live',
            'body'  => $this->property->title.' now has a walkable 3D tour.',
            'url'   => route('property.show', $this->property),
        ];
    }
}
