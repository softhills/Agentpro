<?php

namespace App\Notifications;

use App\Models\SavedSearch;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Collection;

/**
 * New listings matching a saved search (FR-M5-07).
 *
 * One message for the batch, not one per listing. A seeker who saved a broad
 * search would otherwise get six emails in a morning and unsubscribe from all
 * of them.
 */
class SavedSearchMatches extends AgentproNotification
{
    /** @param Collection<int,\App\Models\Property> $properties */
    public function __construct(public SavedSearch $search, public Collection $properties) {}

    protected function preferredChannels(): array
    {
        return ['push', 'email'];
    }

    protected function category(): ?string
    {
        return 'saved_searches';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = $this->properties->count();

        $mail = (new MailMessage)
            ->subject($count.' new '.str('listing')->plural($count).' for "'.$this->search->name.'"')
            ->greeting('Hello '.$notifiable->name)
            ->line('New matches for '.$this->search->describe().':');

        // The price and the area are what decide whether this is worth a click,
        // so they travel in the message rather than behind it.
        foreach ($this->properties->take(5) as $property) {
            $unit = $property->headlineUnit();

            $mail->line(
                '**'.$property->title.'** — '
                .Money::naira($unit?->price).($unit?->price_period->suffix() ?? '')
                .' · '.($property->area?->name ?? $property->city)
                .($property->isRealsureVerified() ? ' · RealSure verified' : '')
            );
        }

        if ($count > 5) {
            $mail->line('…and '.($count - 5).' more.');
        }

        $mail->action('See all matches', route('search', $this->searchLink()));

        return $this->withUnsubscribe($mail, $notifiable);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'  => 'saved_search.matches',
            'title' => $this->properties->count().' new for "'.$this->search->name.'"',
            'body'  => $this->properties->take(3)->pluck('title')->implode(', '),
            'url'   => route('search', $this->searchLink()),
            'saved_search' => $this->search->id,
        ];
    }

    /** Reopens the search the seeker actually saved, not a generic results page. */
    private function searchLink(): array
    {
        return array_filter(
            array_merge($this->search->criteria ?? [], $this->search->bounds ?? []),
            fn ($value) => $value !== null && $value !== ''
        );
    }
}
