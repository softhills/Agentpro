<?php

namespace App\Notifications;

use App\Models\Property;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The alert a seeker gets about a listing they cared about (FR-M9-05).
 *
 * It states what changed rather than saying "this listing was updated", because
 * an alert that does not say what happened forces a round trip to find out, and
 * most people will not make it.
 */
class ListingUpdated extends AgentproNotification
{
    /** @param list<string> $changes */
    public function __construct(public Property $property, public array $changes) {}

    /** No SMS: high volume, and about a listing the seeker is browsing rather
     *  than about their own account. See SavedSearchMatches for the reasoning. */
    protected function preferredChannels(): array
    {
        return ['push', 'email'];
    }

    /** FR-M9-07: seekers can switch this category off without losing everything else. */
    protected function category(): ?string
    {
        return 'listing_updates';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Update on '.$this->property->title)
            ->greeting('Hello '.$notifiable->name)
            ->line('A listing you saved has changed:');

        foreach ($this->changes as $change) {
            $mail->line('• '.$change);
        }

        $mail->action('View the listing', route('property.show', $this->property))
             ->line('You are getting this because you saved or enquired about this property.');

        return $this->withUnsubscribe($mail, $notifiable)->salutation('');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'     => 'listing.updated',
            'title'    => 'Update on '.$this->property->title,
            'body'     => implode(' ', $this->changes),
            'url'      => route('property.show', $this->property),
            'property' => $this->property->uuid,
        ];
    }
}
