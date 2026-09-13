<?php

namespace App\Services\Messaging;

use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Termii (FR-M9-08).
 *
 * Chosen over Twilio for this market: it terminates on the Nigerian networks
 * directly, prices in naira, and — the part that actually decides it — it has a
 * DND-cleared route.
 *
 * Do Not Disturb is the thing that makes Nigerian SMS different. Subscribers on
 * MTN, Glo and Airtel are opted out of promotional traffic at the network, and
 * a message sent on the ordinary route to a DND number is accepted, billed, and
 * never delivered. Transactional traffic — an appointment, a receipt, a
 * verification code — is allowed through a separate route, and using it for
 * marketing is what gets a sender ID banned. So the caller has to say which it
 * is, and nothing in this application sends marketing by SMS.
 */
class TermiiSender implements SmsSender
{
    public function __construct(
        private string $apiKey,
        private string $senderId,
        private string $baseUrl = 'https://api.ng.termii.com',
    ) {
        if ($apiKey === '' || $senderId === '') {
            throw new RuntimeException('Termii needs both an API key and a registered sender ID.');
        }
    }

    public function send(string $toE164, string $body, bool $transactional = true): SmsDelivery
    {
        $msisdn = PhoneNumber::msisdn($toE164);

        if ($msisdn === null) {
            return SmsDelivery::failed('That is not a usable Nigerian mobile number.');
        }

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout(15)
                ->retry(2, 300)
                ->post('/api/sms/send', [
                    'api_key' => $this->apiKey,
                    'to'      => $msisdn,
                    'from'    => $this->senderId,
                    'sms'     => $body,
                    'type'    => 'plain',
                    // 'dnd' is the route that reaches a subscriber who has opted
                    // out of promotional traffic. 'generic' is cheaper and is
                    // the right choice for anything that is not transactional.
                    'channel' => $transactional ? 'dnd' : 'generic',
                ]);
        } catch (\Throwable $e) {
            return SmsDelivery::failed($e->getMessage());
        }

        if (! $response->successful()) {
            Log::warning('sms.termii.rejected', [
                'status'  => $response->status(),
                'message' => $response->json('message'),
            ]);

            return SmsDelivery::failed(
                'Termii returned '.$response->status().': '.($response->json('message') ?: 'no reason given')
            );
        }

        // Termii answers 200 with a message_id on success and 200 with an error
        // message on some failures, so the status code alone is not the answer.
        $messageId = $response->json('message_id');

        if (! $messageId) {
            return SmsDelivery::failed((string) ($response->json('message') ?: 'Termii accepted nothing.'));
        }

        // No cost is reported: Termii returns the remaining account balance on
        // send, not what this message charged, and inferring one from the other
        // across concurrent sends would be a guess. Segments are recorded
        // locally instead, which is what the bill is actually calculated from.
        return SmsDelivery::sent(providerId: (string) $messageId);
    }

    public function name(): string
    {
        return 'termii';
    }
}
