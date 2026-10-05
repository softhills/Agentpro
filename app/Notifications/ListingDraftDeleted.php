<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Your draft was deleted" (SEC-03, FR-M2-07).
 *
 * A sibling of PasswordChanged in the way that matters: its entire value lies
 * in reaching somebody who did *not* do the thing it describes. A lister who
 * deletes their own draft is told so by the screen they did it on, and is never
 * sent this; it goes out only when staff delete a draft that is not theirs.
 *
 * Until this existed, the audit log was the only record, which is accountability
 * facing inwards — it tells the platform who did it, and tells the lister
 * nothing. They would have found out by looking for work that was no longer
 * there.
 *
 * Carries strings rather than the Property. By the time this is constructed the
 * row has gone, its children have cascaded and the photographs are off the
 * disk; a queued notification holding a deleted model would fail to resolve it
 * when the job ran. For the same reason there is nowhere to link to — the only
 * honest action is starting again.
 *
 * The note travels with it because without one the message is an accusation
 * with no case attached, and the lister's only remaining move is a support
 * ticket asking why.
 */
class ListingDraftDeleted extends AgentproNotification
{
    public function __construct(public string $title, public ?string $note = null) {}

    /**
     * No SMS. It is irreversible, so it is not urgent in the sense that word
     * has here — there is nothing the lister can do in the next ten minutes
     * that they cannot do tomorrow, and nothing worth a 2am buzz.
     */
    protected function preferredChannels(): array
    {
        return ['email', 'push'];
    }

    /**
     * No category, so it cannot be switched off. This is a service message
     * about the person's own work being destroyed, not an alert about somebody
     * else's listing.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Your draft listing was deleted: '.$this->title)
            ->greeting('Hello '.$notifiable->name)
            ->line('Your draft "'.$this->title.'" has been deleted by the Agentpro team, '
                  .'along with the photographs on it.')
            ->line($this->note
                ? 'The reason recorded was: '.$this->note
                : 'No reason was recorded with the deletion.')
            ->line('It had not been submitted for review, so it was never visible to anyone '
                  .'searching — but the work on it is gone and cannot be restored.')
            ->action('Start a new listing', route('lister.listings.create'))
            ->line('If you believe this was a mistake, contact '.config('agentpro.company.email')
                  .' and quote the listing title.');

        return $this->withUnsubscribe($mail, $notifiable);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'  => 'listing.draft_deleted',
            'title' => 'Your draft was deleted',
            'body'  => '"'.$this->title.'" was deleted by the Agentpro team'
                      .($this->note ? ': '.$this->note : '.'),
            'url'   => route('lister.dashboard'),
        ];
    }
}
