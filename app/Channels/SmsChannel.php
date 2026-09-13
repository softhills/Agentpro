<?php

namespace App\Channels;

use App\Models\SmsMessage;
use App\Services\Messaging\SmsSender;
use App\Support\PhoneNumber;
use App\Support\SmsText;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Delivers a notification by SMS (FR-M9-08).
 *
 * The only channel with a per-message cost, which shapes every decision here:
 *
 *  - A notification opts in by implementing toSms(). Most do not, and should
 *    not — SMS is for the things that must arrive on a phone that is not
 *    online.
 *  - Copy is normalised before it is measured, because one curly apostrophe
 *    turns a 160-character allowance into a 70-character one and triples the
 *    bill for the same words.
 *  - A message over the segment ceiling is trimmed rather than sent long. An
 *    SMS is a nudge towards the app, not the content itself, so the link
 *    matters and the third paragraph does not.
 *  - Every send is recorded, successful or not, because the invoice arrives
 *    monthly and has to be answerable.
 */
class SmsChannel
{
    public function __construct(private SmsSender $sender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSms')) {
            return;
        }

        $phone = PhoneNumber::e164($notifiable->phone ?? null);

        if ($phone === null) {
            Log::info('sms.skipped_no_number', ['notifiable' => $notifiable->id ?? null]);

            return;
        }

        $body = SmsText::fit(
            SmsText::normalise($notification->toSms($notifiable)),
            (int) config('agentpro.sms.max_segments'),
        );

        if (trim($body) === '') {
            return;
        }

        $delivery = $this->sender->send($phone, $body, transactional: true);

        SmsMessage::create([
            'user_id'             => $notifiable->id ?? null,
            'to_phone'            => $phone,
            'body'                => $body,
            'segments'            => SmsText::segments($body),
            'transactional'       => true,
            'provider'            => $this->sender->name(),
            'provider_message_id' => $delivery->providerId,
            'state'               => $delivery->accepted ? 'sent' : 'failed',
            'failure_reason'      => $delivery->error,
            'cost'                => $delivery->cost,
            'notification_type'   => class_basename($notification),
        ]);

        if (! $delivery->accepted) {
            Log::warning('sms.failed', [
                'notifiable' => $notifiable->id ?? null,
                'reason'     => $delivery->error,
            ]);
        }
    }
}
