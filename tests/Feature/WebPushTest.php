<?php

namespace Tests\Feature;

use App\Channels\WebPushChannel;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\ListingApproved;
use App\Services\Push\P256;
use App\Services\Push\PushPayload;
use App\Services\Push\Vapid;
use App\Services\Push\WebPushSender;
use App\Support\Base64Url;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Web push (FR-M9-08).
 *
 * The encryption tests come first and matter most. Getting the key schedule
 * wrong does not throw and does not fail the request — the push service accepts
 * the body, returns 201, and the browser silently discards a message it cannot
 * decrypt. There is no error anywhere; the feature simply does not work. The
 * only way to know it is right is to reproduce the RFC's own published vector,
 * which is what these do.
 */
class WebPushTest extends TestCase
{
    use RefreshDatabase;

    /*
     * RFC 8291 §5, verbatim. The receiver's key pair, the sender's key pair and
     * the salt are all fixed so the output is deterministic.
     */
    private const RFC_PLAINTEXT = 'When I grow up, I want to be a watermelon';

    private const RFC_UA_PUBLIC = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';

    private const RFC_UA_PRIVATE = 'q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94';

    private const RFC_AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';

    private const RFC_AS_PUBLIC = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';

    private const RFC_AS_PRIVATE = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';

    private const RFC_SALT = 'DGv6ra1nlYgDCS1FRnbzlw';

    private const RFC_BODY = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

    private function user(array $attrs = []): User
    {
        return User::forceCreate(array_merge([
            'uuid' => Str::uuid(), 'name' => 'Tunde Adeyemi',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'x', 'category' => 'sellers_agent',
            'verification_state' => 'verified', 'verified_at' => now(),
        ], $attrs));
    }

    private function subscription(User $user, string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123'): PushSubscription
    {
        return PushSubscription::create([
            'user_id'       => $user->id,
            'endpoint'      => $endpoint,
            'endpoint_hash' => PushSubscription::hashFor($endpoint),
            'p256dh'        => self::RFC_UA_PUBLIC,
            'auth'          => self::RFC_AUTH,
        ]);
    }

    // ------------------------------------------------------------- encryption

    /** The whole feature rests on this one assertion. */
    public function test_encryption_reproduces_the_rfc_8291_test_vector(): void
    {
        $body = PushPayload::encrypt(
            self::RFC_PLAINTEXT,
            Base64Url::decode(self::RFC_UA_PUBLIC),
            Base64Url::decode(self::RFC_AUTH),
            salt: Base64Url::decode(self::RFC_SALT),
            ephemeral: [
                'private' => P256::privateFromScalar(
                    Base64Url::decode(self::RFC_AS_PRIVATE),
                    Base64Url::decode(self::RFC_AS_PUBLIC),
                ),
                'public' => Base64Url::decode(self::RFC_AS_PUBLIC),
            ],
        );

        $this->assertSame(self::RFC_BODY, Base64Url::encode($body));
    }

    /** And from the browser's side, which is the half that has to work. */
    public function test_the_rfc_vector_decrypts_back_to_its_plaintext(): void
    {
        $this->assertSame(
            self::RFC_PLAINTEXT,
            PushPayload::decrypt(
                Base64Url::decode(self::RFC_BODY),
                Base64Url::decode(self::RFC_UA_PRIVATE),
                Base64Url::decode(self::RFC_UA_PUBLIC),
                Base64Url::decode(self::RFC_AUTH),
            )
        );
    }

    /**
     * A fresh ephemeral key and salt every time, so the same message to the
     * same subscription is never the same bytes. Reusing either would leak the
     * relationship between messages to whoever is relaying them.
     */
    public function test_two_encryptions_of_the_same_message_differ(): void
    {
        $args = [self::RFC_PLAINTEXT, Base64Url::decode(self::RFC_UA_PUBLIC), Base64Url::decode(self::RFC_AUTH)];

        $this->assertNotSame(
            PushPayload::encrypt(...$args),
            PushPayload::encrypt(...$args),
        );
    }

    public function test_an_oversized_payload_is_refused_rather_than_truncated(): void
    {
        $this->expectException(\RuntimeException::class);

        PushPayload::encrypt(
            str_repeat('x', PushPayload::MAX_PLAINTEXT + 1),
            Base64Url::decode(self::RFC_UA_PUBLIC),
            Base64Url::decode(self::RFC_AUTH),
        );
    }

    public function test_a_private_key_round_trips_through_configuration(): void
    {
        $pair = P256::generate();

        $restored = P256::privateFromScalar(P256::scalarOf($pair['private']), $pair['public']);

        $this->assertSame(
            bin2hex($pair['public']),
            bin2hex(P256::pointOf($restored)),
            'a key stored in .env and read back must be the same key'
        );
    }

    // ------------------------------------------------------------------ VAPID

    /**
     * The audience is the origin, not the endpoint.
     *
     * Signing the full URL produces a token every push service rejects, and the
     * only feedback is an unexplained 401.
     */
    public function test_the_vapid_audience_is_the_origin_only(): void
    {
        $vapid = $this->vapid();

        $this->assertSame(
            'https://fcm.googleapis.com',
            $vapid->audienceFor('https://fcm.googleapis.com/fcm/send/abc123?x=1'),
        );

        $this->assertSame(
            'https://push.example.test:8443',
            $vapid->audienceFor('https://push.example.test:8443/push/xyz'),
            'a non-default port is part of the origin'
        );
    }

    public function test_the_vapid_token_verifies_against_the_published_key(): void
    {
        $pair = P256::generate();
        $public = Base64Url::encode($pair['public']);

        config([
            'agentpro.push.public_key'  => $public,
            'agentpro.push.private_key' => Base64Url::encode(P256::scalarOf($pair['private'])),
            'agentpro.push.subject'     => 'mailto:ops@example.test',
        ]);

        $header = Vapid::fromConfig()->headers('https://fcm.googleapis.com/fcm/send/abc');

        $this->assertStringStartsWith('vapid t=', $header['Authorization']);
        $this->assertStringContainsString('k='.$public, $header['Authorization']);

        [$token] = explode(', ', substr($header['Authorization'], strlen('vapid t=')));
        [$head, $claims, $signature] = explode('.', $token);

        $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(Base64Url::decode($head), true));

        $payload = json_decode(Base64Url::decode($claims), true);
        $this->assertSame('https://fcm.googleapis.com', $payload['aud']);
        $this->assertSame('mailto:ops@example.test', $payload['sub']);
        $this->assertGreaterThan(time(), $payload['exp']);
        // RFC 8292 caps a token's life at 24 hours; a longer one is rejected.
        $this->assertLessThanOrEqual(time() + 86400, $payload['exp']);

        $this->assertTrue(
            $this->verifies($head.'.'.$claims, Base64Url::decode($signature), $pair['public']),
            'the signature must verify against the key we publish to browsers'
        );
    }

    public function test_a_subject_that_nobody_can_be_reached_at_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        new Vapid(P256::generate()['public'], str_repeat("\0", 32), 'ops@example.test');
    }

    // --------------------------------------------------------------- delivery

    public function test_a_push_is_posted_to_the_endpoint_with_a_vapid_header(): void
    {
        $pair = P256::generate();
        config([
            'agentpro.push.public_key'  => Base64Url::encode($pair['public']),
            'agentpro.push.private_key' => Base64Url::encode(P256::scalarOf($pair['private'])),
            'agentpro.push.subject'     => 'mailto:ops@example.test',
        ]);

        Http::fake(['https://fcm.googleapis.com/*' => Http::response('', 201)]);

        $subscription = $this->subscription($this->user());

        $delivery = app(WebPushSender::class)->send($subscription, ['title' => 'Listing approved']);

        $this->assertTrue($delivery->delivered);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Content-Encoding', 'aes128gcm')
                && $request->hasHeader('TTL')
                && str_starts_with($request->header('Authorization')[0], 'vapid t=')
                // The body must be ciphertext, never the payload in the clear.
                && ! str_contains($request->body(), 'Listing approved');
        });
    }

    /**
     * 410 is the push service saying the browser is gone for good. A
     * subscription nobody prunes costs a queued job and an HTTP round trip on
     * every notification, forever.
     */
    public function test_a_dead_subscription_is_pruned(): void
    {
        $user = $this->user();
        $this->subscription($user);

        $this->bindSender(fn () => \App\Services\Push\PushDelivery::expired(410));

        app(WebPushChannel::class)->send($user, new ListingApproved($this->property($user)));

        $this->assertSame(0, PushSubscription::count());
    }

    /**
     * A push service having a bad afternoon must not unsubscribe everybody.
     */
    public function test_a_transient_failure_keeps_the_subscription(): void
    {
        config(['agentpro.push.give_up_after' => 3]);

        $user = $this->user();
        $this->subscription($user);

        $this->bindSender(fn () => \App\Services\Push\PushDelivery::failed('503 from the push service', 503));

        $notification = new ListingApproved($this->property($user));

        app(WebPushChannel::class)->send($user, $notification);

        $this->assertSame(1, PushSubscription::count());
        $this->assertSame(1, PushSubscription::first()->failure_count);

        // But one that keeps failing is dead in a way the service has not
        // admitted to, and is eventually dropped.
        app(WebPushChannel::class)->send($user, $notification);
        app(WebPushChannel::class)->send($user, $notification);

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_every_device_gets_the_push(): void
    {
        $user = $this->user();
        $this->subscription($user, 'https://fcm.googleapis.com/fcm/send/phone');
        $this->subscription($user, 'https://updates.push.services.mozilla.com/wpush/v2/laptop');

        $sent = 0;
        $this->bindSender(function () use (&$sent) {
            $sent++;

            return \App\Services\Push\PushDelivery::sent();
        });

        app(WebPushChannel::class)->send($user, new ListingApproved($this->property($user)));

        $this->assertSame(2, $sent, 'a phone and a laptop are two subscriptions');
        $this->assertNotNull(PushSubscription::first()->last_used_at);
    }

    // ------------------------------------------------------------ registering

    public function test_a_browser_can_register_and_re_registering_does_not_duplicate(): void
    {
        $user = $this->user();

        $payload = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => ['p256dh' => self::RFC_UA_PUBLIC, 'auth' => self::RFC_AUTH],
        ];

        $this->actingAs($user)->postJson(route('push.subscribe'), $payload)->assertOk();

        // The client re-registers on every page load by design, so this has to
        // be an upsert or the user receives everything several times over.
        $this->actingAs($user)->postJson(route('push.subscribe'), $payload)->assertOk();

        $this->assertSame(1, PushSubscription::count());
        $this->assertSame($user->id, PushSubscription::first()->user_id);
    }

    /** An endpoint is not a secret, so unsubscribing is scoped to its owner. */
    public function test_one_person_cannot_unsubscribe_another(): void
    {
        $owner = $this->user();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123';
        $this->subscription($owner, $endpoint);

        $this->actingAs($this->user())
            ->postJson(route('push.unsubscribe'), ['endpoint' => $endpoint])
            ->assertOk();

        $this->assertSame(1, PushSubscription::count());

        $this->actingAs($owner)
            ->postJson(route('push.unsubscribe'), ['endpoint' => $endpoint])
            ->assertOk();

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_registering_requires_an_account(): void
    {
        $this->postJson(route('push.subscribe'), [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
            'keys' => ['p256dh' => self::RFC_UA_PUBLIC, 'auth' => self::RFC_AUTH],
        ])->assertUnauthorized();
    }

    /** Without keys the button must say so rather than failing on tap. */
    public function test_the_public_key_endpoint_reports_an_unconfigured_server(): void
    {
        config(['agentpro.push.public_key' => '', 'agentpro.push.private_key' => '']);

        $this->actingAs($this->user())
            ->getJson(route('push.key'))
            ->assertOk()
            ->assertJson(['key' => null]);
    }

    public function test_a_device_can_be_removed_from_the_settings_page(): void
    {
        $user = $this->user();
        $subscription = $this->subscription($user);

        $this->actingAs($this->user())
            ->delete(route('push.forget', $subscription))
            ->assertNotFound();

        $this->actingAs($user)
            ->delete(route('push.forget', $subscription))
            ->assertRedirect();

        $this->assertSame(0, PushSubscription::count());
    }

    // ------------------------------------------------------------------ wiring

    /** Push used to be quietly rerouted to the inbox. It must not be now. */
    public function test_push_routes_to_the_push_channel_and_no_longer_to_the_inbox(): void
    {
        $user = $this->user();
        $user->update(['notification_preferences' => [
            'push' => true, 'email' => false, 'whatsapp' => false, 'sms' => false,
            'quiet_enabled' => false,
        ]]);

        $channels = (new ListingApproved($this->property($user)))->via($user);

        $this->assertContains(WebPushChannel::class, $channels);
        $this->assertContains('database', $channels, 'the inbox is always kept');
    }

    // ----------------------------------------------------------------- helpers

    private function vapid(): Vapid
    {
        $pair = P256::generate();

        return new Vapid($pair['public'], P256::scalarOf($pair['private']), 'mailto:ops@example.test');
    }

    private function bindSender(\Closure $result): void
    {
        $this->app->bind(WebPushSender::class, fn () => new class($result) implements WebPushSender
        {
            public function __construct(private \Closure $result) {}

            public function publicKey(): ?string
            {
                return 'test-key';
            }

            public function send(PushSubscription $subscription, array $payload): \App\Services\Push\PushDelivery
            {
                return ($this->result)($subscription, $payload);
            }
        });
    }

    /** Rebuild a DER signature from raw R||S and let OpenSSL check it. */
    private function verifies(string $message, string $rawSignature, string $publicPoint): bool
    {
        $integer = function (string $value): string {
            $value = ltrim($value, "\0");

            if (ord($value[0]) > 0x7f) {
                $value = "\0".$value;
            }

            return "\x02".chr(strlen($value)).$value;
        };

        $body = $integer(substr($rawSignature, 0, 32)).$integer(substr($rawSignature, 32));
        $der = "\x30".chr(strlen($body)).$body;

        return openssl_verify($message, $der, openssl_pkey_get_public(P256::publicPem($publicPoint)), OPENSSL_ALGO_SHA256) === 1;
    }

    private function property(User $lister): \App\Models\Property
    {
        $area = \App\Models\Area::firstOrCreate(['slug' => 'ikoyi'], [
            'name' => 'Ikoyi', 'city' => 'Lagos', 'state' => 'Lagos', 'is_scan_coverage' => true,
        ]);

        return \App\Models\Property::create([
            'uuid' => Str::uuid(), 'lister_id' => $lister->id, 'area_id' => $area->id,
            'title' => '3-Bed Apartment, Ikoyi', 'slug' => 'ikoyi-'.Str::lower(Str::random(6)),
            'listing_type' => 'apartment', 'intent' => 'rent', 'build_status' => 'fully_built',
            'address_line' => '12 Bourdillon Road', 'city' => 'Lagos', 'state' => 'Lagos',
            'lat' => 6.4488, 'lng' => 3.4390,
            'location' => \Illuminate\Support\Facades\DB::raw("ST_GeomFromText('POINT(3.4390 6.4488)')"),
            'lifecycle_state' => 'published', 'published_at' => now(),
        ]);
    }
}
