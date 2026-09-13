<?php

namespace App\Services\Messaging;

use App\Support\PhoneNumber;
use App\Support\SmsText;
use Illuminate\Support\Facades\Log;

/**
 * Development sender.
 *
 * Writes what would have gone out, including the segment count — which is the
 * number worth seeing during development, because it is what the message will
 * cost and it is invisible in the text itself.
 *
 * It still rejects an unusable number rather than accepting everything. A
 * development driver that succeeds unconditionally hides exactly the bug that
 * matters here: a badly stored phone number that will be billed and never
 * delivered.
 */
class LogSmsSender implements SmsSender
{
    public function send(string $toE164, string $body, bool $transactional = true): SmsDelivery
    {
        if (PhoneNumber::e164($toE164) === null) {
            return SmsDelivery::failed('That is not a usable Nigerian mobile number.');
        }

        Log::info('sms.would_send', [
            'to'       => $toE164,
            'route'    => $transactional ? 'dnd' : 'generic',
            'segments' => SmsText::segments($body),
            'body'     => $body,
        ]);

        return SmsDelivery::sent(providerId: 'log_'.bin2hex(random_bytes(6)));
    }

    public function name(): string
    {
        return 'log';
    }
}
