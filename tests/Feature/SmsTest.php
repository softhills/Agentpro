<?php

namespace Tests\Feature;

use App\Channels\SmsChannel;
use App\Models\SmsMessage;
use App\Models\User;
use App\Notifications\SavedSearchMatches;
use App\Notifications\VerificationDecided;
use App\Services\Messaging\SmsDelivery;
use App\Services\Messaging\SmsSender;
use App\Services\Messaging\TermiiSender;
use App\Support\PhoneNumber;
use App\Support\SmsText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SMS (FR-M9-08).
 *
 * The only channel billed per message, which is what most of these are about.
 * The two ways to waste money on SMS are both silent: a number that is billed
 * and undeliverable, and a message that costs three segments because of one
 * character nobody looked at.
 */
class SmsTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attrs = []): User
    {
        return User::forceCreate(array_merge([
            'uuid' => Str::uuid(), 'name' => 'Tunde Adeyemi',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'x', 'category' => 'sellers_agent',
            'phone' => '08030000001',
            'verification_state' => 'verified', 'verified_at' => now(),
        ], $attrs));
    }

    // ----------------------------------------------------------------- numbers

    public function test_a_number_is_normalised_however_it_was_typed(): void
    {
        foreach ([
            '08030000001',        // how a Nigerian writes it
            '0803 000 0001',      // with spacing
            '+2348030000001',     // E.164 already
            '2348030000001',      // without the plus
            '8030000001',         // without the trunk zero
            '+234 803 000 0001',  // spaced E.164
            '0803-000-0001',
        ] as $input) {
            $this->assertSame('+2348030000001', PhoneNumber::e164($input), 'failed on '.$input);
        }
    }

    /**
     * A number we cannot reach is rejected rather than guessed at.
     *
     * Guessing is the expensive mistake here: a mangled number is accepted by
     * the gateway, billed, and delivered nowhere.
     */
    public function test_numbers_that_cannot_receive_an_sms_are_refused(): void
    {
        foreach ([
            '012345678',        // Lagos landline
            '0803000000',       // one digit short
            '080300000012',     // one too many
            '+447700900000',    // not Nigerian
            '',
            null,
        ] as $input) {
            $this->assertNull(PhoneNumber::e164($input), 'should have refused '.var_export($input, true));
        }
    }

    public function test_a_number_is_shown_back_the_way_it_is_dialled(): void
    {
        $this->assertSame('0803 000 0001', PhoneNumber::national('+2348030000001'));
    }

    // ---------------------------------------------------------------- segments

    public function test_plain_text_gets_the_full_160_characters(): void
    {
        $this->assertSame(1, SmsText::segments(str_repeat('a', 160)));
        // Past 160 the message is split, and each part carries a header, so the
        // allowance drops to 153 — not 160.
        $this->assertSame(2, SmsText::segments(str_repeat('a', 161)));
        $this->assertSame(2, SmsText::segments(str_repeat('a', 306)));
        $this->assertSame(3, SmsText::segments(str_repeat('a', 307)));
    }

    /**
     * One character outside GSM 03.38 re-encodes the whole message and cuts the
     * allowance from 160 to 70. This is the trap the class exists for.
     */
    public function test_a_single_naira_sign_more_than_doubles_the_cost(): void
    {
        $plain = str_repeat('a', 100);
        $withNaira = '₦'.str_repeat('a', 99);

        $this->assertSame(1, SmsText::segments($plain));
        $this->assertSame(2, SmsText::segments($withNaira), 'UCS-2 allows only 70 characters');

        // And normalising it puts the message back into one segment.
        $this->assertSame(1, SmsText::segments(SmsText::normalise($withNaira)));
    }

    public function test_a_pasted_curly_apostrophe_is_normalised_away(): void
    {
        $this->assertFalse(SmsText::isGsm7('Your listing’s live'));
        $this->assertTrue(SmsText::isGsm7(SmsText::normalise('Your listing’s live')));
        $this->assertTrue(SmsText::isGsm7(SmsText::normalise('Ikoyi — ₦7,500,000 … done')));
    }

    public function test_extended_characters_count_twice(): void
    {
        // '[' is in the GSM extension table and takes two septets.
        $this->assertSame(2, SmsText::length('['));
        $this->assertSame(1, SmsText::length('a'));
    }

    public function test_a_long_message_is_trimmed_to_the_budget_and_stays_cheap(): void
    {
        $trimmed = SmsText::fit(str_repeat('word ', 200), 2);

        $this->assertLessThanOrEqual(2, SmsText::segments($trimmed));
        $this->assertStringEndsWith('...', $trimmed);
        // The ellipsis character would itself force UCS-2, undoing the trim.
        $this->assertTrue(SmsText::isGsm7($trimmed));
    }

    public function test_a_message_that_already_fits_is_left_alone(): void
    {
        $this->assertSame('Short message.', SmsText::fit('Short message.', 2));
    }

    // ----------------------------------------------------------------- sending

    public function test_a_send_is_recorded_with_what_it_will_cost(): void
    {
        $user = $this->user();

        app(SmsChannel::class)->send($user, new VerificationDecided('verified'));

        $message = SmsMessage::firstOrFail();

        $this->assertSame($user->id, $message->user_id);
        $this->assertSame('+2348030000001', $message->to_phone);
        $this->assertSame('sent', $message->state);
        $this->assertSame(1, $message->segments);
        $this->assertSame('VerificationDecided', $message->notification_type);
        $this->assertTrue($message->transactional, 'account messages go on the DND-cleared route');
    }

    /** Nothing is billed for a number that could never receive it. */
    public function test_a_user_with_no_usable_number_is_skipped_entirely(): void
    {
        $user = $this->user(['phone' => '012345678']);

        app(SmsChannel::class)->send($user, new VerificationDecided('verified'));

        $this->assertSame(0, SmsMessage::count());
    }

    /** A failure still costs money at some gateways, and always costs an answer. */
    public function test_a_failed_send_is_recorded_too(): void
    {
        $this->app->bind(SmsSender::class, fn () => new class implements SmsSender
        {
            public function send(string $toE164, string $body, bool $transactional = true): SmsDelivery
            {
                return SmsDelivery::failed('Insufficient balance.');
            }

            public function name(): string
            {
                return 'stub';
            }
        });

        app(SmsChannel::class)->send($this->user(), new VerificationDecided('verified'));

        $message = SmsMessage::firstOrFail();

        $this->assertSame('failed', $message->state);
        $this->assertSame('Insufficient balance.', $message->failure_reason);
    }

    /**
     * The body that reaches the gateway is the normalised one.
     *
     * Asserted through the stored row rather than a spy, because the row is
     * what the invoice gets reconciled against — if the two ever differed, the
     * record would be the lie.
     */
    public function test_outgoing_copy_is_normalised_before_it_is_sent(): void
    {
        app(SmsChannel::class)->send(
            $this->user(),
            new VerificationDecided('rejected', 'The name didn’t match — try again.'),
        );

        $message = SmsMessage::firstOrFail();

        $this->assertTrue(SmsText::isGsm7($message->body), 'one curly quote would have doubled the cost');
        $this->assertStringNotContainsString('’', $message->body);
        $this->assertSame(1, $message->segments);
    }

    // ------------------------------------------------------------------ Termii

    public function test_termii_is_called_on_the_dnd_route_for_transactional_messages(): void
    {
        Http::fake(['*/api/sms/send' => Http::response(['message_id' => 'tm_123', 'message' => 'Successfully Sent'], 200)]);

        $delivery = (new TermiiSender('key', 'Agentpro'))->send('+2348030000001', 'Hello');

        $this->assertTrue($delivery->accepted);
        $this->assertSame('tm_123', $delivery->providerId);

        Http::assertSent(function ($request) {
            // Digits, no plus — and the route that reaches a subscriber who has
            // opted out of promotional traffic.
            return $request['to'] === '2348030000001'
                && $request['channel'] === 'dnd'
                && $request['from'] === 'Agentpro';
        });
    }

    /** Termii answers 200 with an error message on some failures. */
    public function test_a_200_with_no_message_id_is_a_failure(): void
    {
        Http::fake(['*/api/sms/send' => Http::response(['message' => 'Invalid Sender ID'], 200)]);

        $delivery = (new TermiiSender('key', 'Agentpro'))->send('+2348030000001', 'Hello');

        $this->assertFalse($delivery->accepted);
        $this->assertSame('Invalid Sender ID', $delivery->error);
    }

    public function test_termii_refuses_a_number_it_cannot_reach_without_calling_out(): void
    {
        Http::fake();

        $this->assertFalse((new TermiiSender('key', 'Agentpro'))->send('012345678', 'Hello')->accepted);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ wiring

    public function test_sms_routes_to_the_sms_channel_and_no_longer_to_the_inbox(): void
    {
        $user = $this->user();
        $user->update(['notification_preferences' => [
            'sms' => true, 'push' => false, 'email' => false, 'whatsapp' => false,
            'quiet_enabled' => false,
        ]]);

        $channels = (new VerificationDecided('verified'))->via($user);

        $this->assertContains(SmsChannel::class, $channels);
        $this->assertContains('database', $channels);
    }

    /**
     * Search results deliberately never go by SMS.
     *
     * It is the highest-volume message the platform sends, and putting it on
     * the DND-cleared route is exactly the abuse that gets a Nigerian sender ID
     * blocked for everything else — including the messages that matter.
     */
    public function test_discovery_notifications_have_no_sms_body_at_all(): void
    {
        $this->assertFalse(
            method_exists(SavedSearchMatches::class, 'toSms'),
            'saved-search alerts must never be sent as SMS'
        );

        $this->assertFalse(method_exists(\App\Notifications\ListingUpdated::class, 'toSms'));
    }

    /** A notification with no SMS body must not produce an empty text. */
    public function test_a_notification_without_an_sms_body_sends_nothing(): void
    {
        $user = $this->user();

        app(SmsChannel::class)->send($user, new \App\Notifications\TourIsLive(
            \App\Models\Property::make(['title' => 'x', 'uuid' => Str::uuid()])
        ));

        $this->assertSame(0, SmsMessage::count());
    }
}
