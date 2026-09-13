<?php

namespace App\Notifications;

use App\Models\DataRequest;
use App\Support\PersonalData;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Your copy is ready" (FR-M1-09, NDPA 2023 s. 38).
 *
 * Deliberately carries no link to the file itself. The download sits behind the
 * session on the privacy screen, so a forwarded message — or a mailbox somebody
 * else has got into — is not on its own a copy of everything we hold about this
 * person. Telling them out of band and making them come and get it is the whole
 * control, and it costs one click.
 *
 * Email and push only. This is not urgent enough to be worth an SMS, and the
 * point of the message is that it lands somewhere the person will see it before
 * the file expires.
 */
class AccountDataReady extends AgentproNotification
{
    public function __construct(public DataRequest $request) {}

    protected function preferredChannels(): array
    {
        return ['email', 'push'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Your Agentpro data is ready to download')
            ->greeting('Hello '.$notifiable->name)
            ->line('The copy of your Agentpro data you asked for is ready.')
            ->line('You will need to be signed in to download it — we do not put a copy of '
                  .'everything we hold about you behind a link in an email.')
            ->line('It is removed after '.PersonalData::exportExpiryHours()
                  .' hours. Asking again is free if you miss it.')
            ->action('Download it', route('privacy.index'));

        return $this->withUnsubscribe($mail, $notifiable);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'  => 'privacy.export_ready',
            'title' => 'Your data is ready to download',
            'body'  => 'Available for the next '.PersonalData::exportExpiryHours().' hours.',
            'url'   => route('privacy.index'),
        ];
    }
}
