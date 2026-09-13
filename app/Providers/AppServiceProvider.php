<?php

namespace App\Providers;

use App\Models\Property;
use App\Models\Refund;
use App\Models\Settlement;
use App\Models\User;
use App\Policies\PropertyPolicy;
use App\Services\Identity\IdentityVerifier;
use App\Services\Identity\StubVerifier;
use App\Services\Messaging\LogSmsSender;
use App\Services\Messaging\LogWhatsAppSender;
use App\Services\Messaging\SmsSender;
use App\Services\Messaging\TermiiSender;
use App\Services\Messaging\WhatsAppSender;
use App\Services\Push\LogWebPush;
use App\Services\Push\StandardWebPush;
use App\Services\Push\Vapid;
use App\Services\Push\WebPushSender;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaystackGateway;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Identity vendor (PRD Q1, still open).
         *
         * The driver is chosen here and nowhere else, so picking VerifyMe.ng or
         * SmileID later is a one-line change rather than a hunt through
         * controllers. The stub deliberately refuses to load outside local and
         * testing: shipping it to production would mean every lister is
         * "verified" by a random string generator.
         */
        $this->app->bind(IdentityVerifier::class, function ($app) {
            if (! $app->environment('local', 'testing')) {
                throw new \RuntimeException(
                    'No identity verification driver is configured. Bind a real '
                    .'IdentityVerifier before running outside local/testing (PRD Q1).'
                );
            }

            return new StubVerifier();
        });

        /*
         * WhatsApp sender (FR-M9-08).
         *
         * Bound unconditionally: the channel resolves it whenever a user has
         * WhatsApp enabled, and an unbound interface there does not fail the
         * check — it fails the whole notification, after the email has already
         * gone out, leaving a half-delivered message in failed_jobs.
         */
        $this->app->bind(WhatsAppSender::class, function ($app) {
            // A real Meta-backed sender goes here once the business account and
            // approved templates exist. Until then every environment logs what
            // it would have sent rather than pretending to deliver it.
            return new LogWhatsAppSender();
        });

        /*
         * Web push (FR-M9-08).
         *
         * Falls back to the logging driver when no VAPID keys are configured,
         * in any environment — including production. That is deliberate and is
         * the opposite of the payment gateway's rule below, because the
         * consequences are opposite: a missing payment gateway must stop the
         * application, while a missing push key should cost a convenience, not
         * an outage. Everything still reaches the inbox and the email.
         */
        $this->app->bind(WebPushSender::class, function () {
            if (! Vapid::isConfigured()) {
                return new LogWebPush();
            }

            return new StandardWebPush(Vapid::fromConfig());
        });

        /*
         * SMS (FR-M9-08).
         *
         * Same reasoning: an unconfigured gateway logs rather than throws. The
         * one thing it must not do is silently succeed at nothing, which is why
         * LogSmsSender still rejects an unusable number.
         */
        $this->app->bind(SmsSender::class, function () {
            $driver = (string) config('agentpro.sms.driver');
            $apiKey = (string) config('agentpro.sms.termii.api_key');

            if ($driver === 'termii' && $apiKey !== '') {
                return new TermiiSender(
                    $apiKey,
                    (string) config('agentpro.sms.termii.sender_id'),
                    (string) config('agentpro.sms.termii.base_url'),
                );
            }

            return new LogSmsSender();
        });

        /*
         * Payment gateway (M11).
         *
         * Paystack whenever a secret key is configured, in any environment —
         * so pointing a developer at Paystack's own test keys exercises the
         * real integration. The fake is only reached when no key is set, and
         * only in local or testing: a production deployment without credentials
         * must fail loudly rather than quietly accept imaginary payments.
         */
        $this->app->bind(PaymentGateway::class, function ($app) {
            $secret = (string) config('agentpro.paystack.secret_key');

            if ($secret !== '') {
                return new PaystackGateway($secret, (string) config('agentpro.paystack.base_url'));
            }

            if (! $app->environment('local', 'testing')) {
                throw new \RuntimeException(
                    'PAYSTACK_SECRET_KEY is not set. Refusing to process payments without a gateway.'
                );
            }

            return new FakeGateway((string) config('agentpro.paystack.fake_secret'));
        });
    }

    public function boot(): void
    {
        Gate::policy(Property::class, PropertyPolicy::class);

        /*
         * Counts for the admin sidebar badges.
         *
         * Bound to the layout rather than repeated in every admin controller,
         * so a new admin screen cannot ship without them and quietly lose the
         * "something is waiting" signal.
         */
        View::composer('layouts.admin', function ($view) {
            $view->with([
                'queueDepth' => Property::whereIn('lifecycle_state', ['submitted', 'under_review'])->count(),
                'pendingUsers' => User::where('verification_state', 'pending')->count(),
                // A refund waiting on a second approver is blocked on a person,
                // not on a provider, so it belongs on the badge.
                'refundsWaiting' => Refund::where('state', 'requested')->count(),
                'settlementIssues' => Settlement::where('reconciliation_state', 'discrepancy')->count(),
            ]);
        });

        // SEC-13: behind Cloudflare the app sees plain HTTP, so generated URLs
        // must be forced to https outside local or they mix-content on the CDN.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
