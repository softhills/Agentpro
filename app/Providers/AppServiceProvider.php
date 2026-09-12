<?php

namespace App\Providers;

use App\Models\Property;
use App\Policies\PropertyPolicy;
use App\Services\Identity\IdentityVerifier;
use App\Services\Identity\StubVerifier;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaystackGateway;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
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

        // SEC-13: behind Cloudflare the app sees plain HTTP, so generated URLs
        // must be forced to https outside local or they mix-content on the CDN.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
