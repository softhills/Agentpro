<?php

use App\Http\Controllers\Admin\AnalyticsController as AdminAnalyticsController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\ListingAdminController;
use App\Http\Controllers\Admin\ModerationController;
use App\Http\Controllers\Admin\OperationsController;
use App\Http\Controllers\Admin\OrderAdminController;
use App\Http\Controllers\Admin\PayoutAdminController;
use App\Http\Controllers\Admin\SettlementAdminController;
use App\Http\Controllers\Admin\TaxonomyController;
use App\Http\Controllers\Admin\UserAdminController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\VerificationController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InteractionController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\PagesController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\Lister\DashboardController;
use App\Http\Controllers\Lister\ListingAnalyticsController;
use App\Http\Controllers\Lister\ListingController;
use App\Http\Controllers\Lister\MediaController;
use App\Http\Controllers\Lister\PayoutController;
use App\Http\Controllers\Lister\SandboxCheckoutController;
use App\Http\Controllers\Lister\ScanController;
use App\Http\Controllers\Technician\AssignmentController;
use App\Http\Controllers\Webhooks\PaystackWebhookController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\Realsure\ConsoleController as RealsureConsoleController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\SavedSearchController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

/*
| Public — no account required (FR-M1-01).
*/
Route::get('/', HomeController::class)->name('home');
Route::get('/search', [SearchController::class, 'index'])->name('search');

// FR-M9-07: one tap, from any message, without signing in. Signed so it
// cannot be altered to unsubscribe somebody else.
Route::get('/unsubscribe/{user}/{category}', [NotificationPreferenceController::class, 'unsubscribe'])
    ->middleware('signed')
    ->name('unsubscribe');

// The cheapest way to scrape the whole inventory, so it is capped per IP (SEC-10).
Route::get('/search/pins', [SearchController::class, 'pins'])
    ->middleware('throttle:60,1')
    ->name('search.pins');

Route::get('/property/{property}', [PropertyController::class, 'show'])->name('property.show');

/*
| Corporate and product pages (deliverable D2).
|
| Every one of these was a dead href="#" in the header and footer. They are
| public and server-rendered for the same reason listing pages are: this is the
| SEO surface, and a marketing page a crawler cannot read is a marketing page
| that does not exist (FR-M5-08).
|
| The lister profile is bound on `{user:uuid}` rather than by adding a route key
| to the model, because the signed unsubscribe link passes an id and changing
| the default binding would silently break every message already sent (SEC-10).
*/
Route::get('/realsure', [PagesController::class, 'realsure'])->name('pages.realsure');
Route::get('/areas', [PagesController::class, 'areas'])->name('pages.areas');
Route::get('/areas/{area}', [PagesController::class, 'area'])->name('pages.area');
Route::get('/agents', [PagesController::class, 'agents'])->name('pages.agents');
Route::get('/agents/{user:uuid}', [PagesController::class, 'agent'])->name('pages.agent');
Route::get('/about', [PagesController::class, 'about'])->name('pages.about');
Route::get('/terms', [PagesController::class, 'terms'])->name('pages.terms');
Route::get('/privacy', [PagesController::class, 'privacy'])->name('pages.privacy');

// FR-M5-08. Cached, because it walks every published listing and a crawler
// that asks for it hourly should not cost a full table scan each time.
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

/*
| Consent and the analytics beacon (M13). Public, because the seeker funnel
| starts before anybody has an account and the banner has to work for guests.
|
| The beacon is throttled hard. It is the only untrusted way into the event
| store, and the damage it could do is not a breach but a corrupted median —
| far cheaper to cap than to detect afterwards. Two tour opens and a handful of
| dwell readings a minute is more than genuine use produces.
*/
Route::post('/consent', [AnalyticsController::class, 'consent'])
    ->middleware('throttle:20,1')->name('consent');

Route::post('/events', [AnalyticsController::class, 'event'])
    ->middleware('throttle:30,1')->name('events');

/*
| Payment webhook (FR-M4-04, SEC-05).
|
| Outside the auth and CSRF groups by necessity — the caller is Paystack, not a
| browser session. Its authenticity comes from the HMAC signature on the raw
| body, which is checked before anything is acted on.
*/
Route::post('/webhooks/paystack', PaystackWebhookController::class)
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    // Every delivery is recorded before its signature is checked, which is what
    // makes a rejected run visible afterwards — but it also means an attacker
    // sending unique event ids could stuff the table. Generous enough for a
    // provider's genuine retry storm, tight enough to make that pointless.
    ->middleware('throttle:120,1')
    ->name('webhooks.paystack');

/*
| Guest only.
*/
Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:10,1');

    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:20,1');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
| Authenticated.
*/
Route::middleware('auth')->group(function () {
    /*
    | Seeker interactions (M9). These also define the audience for listing
    | alerts, so an entry here is a standing statement of interest.
    */
    Route::prefix('property/{property}')->name('interact.')->group(function () {
        Route::post('/save',    [InteractionController::class, 'toggleSave'])->name('save');
        Route::post('/hide',    [InteractionController::class, 'toggleHide'])->name('hide');
        Route::post('/rate',    [InteractionController::class, 'rate'])->name('rate');
        Route::post('/report',  [InteractionController::class, 'report'])->name('report');
        Route::post('/contact', [InteractionController::class, 'contact'])->name('contact');
    });

    /*
    | Saved searches (FR-M5-07) — the loop that brings a seeker back before
    | they have found anything.
    */
    Route::get('/account/saved-searches', [SavedSearchController::class, 'index'])->name('saved-searches.index');
    Route::post('/saved-searches', [SavedSearchController::class, 'store'])
        ->middleware('throttle:20,1')->name('saved-searches.store');
    Route::put('/saved-searches/{savedSearch}', [SavedSearchController::class, 'update'])->name('saved-searches.update');
    Route::delete('/saved-searches/{savedSearch}', [SavedSearchController::class, 'destroy'])->name('saved-searches.destroy');

    Route::get('/account/notifications', [NotificationPreferenceController::class, 'edit'])->name('notifications.edit');
    Route::put('/account/notifications', [NotificationPreferenceController::class, 'update'])->name('notifications.update');

    /*
    | Your data (FR-M1-09, NDPA ss. 34 and 38).
    |
    | Throttled hard in one direction only. Building an export reads seventeen
    | tables and writes a file, so repeating it is a cheap way to make the
    | server do expensive work — and there is no honest reason to ask twice in
    | an hour. Cancelling is not throttled at all: it is the escape hatch when
    | somebody else is signed in as you, and rate-limiting that would be
    | rate-limiting the victim.
    */
    Route::get('/account/data', [PrivacyController::class, 'index'])->name('privacy.index');
    Route::post('/account/data/export', [PrivacyController::class, 'export'])
        ->middleware('throttle:3,60')->name('privacy.export');
    Route::get('/account/data/{dataRequest}/download', [PrivacyController::class, 'download'])
        ->name('privacy.download');
    Route::post('/account/data/erase', [PrivacyController::class, 'erase'])
        ->middleware('throttle:5,60')->name('privacy.erase');
    Route::post('/account/data/{dataRequest}/cancel', [PrivacyController::class, 'cancel'])
        ->name('privacy.cancel');

    /*
    | Browser push registration (FR-M9-08).
    |
    | Kept inside the CSRF group on purpose. These endpoints decide where a
    | person's notifications are delivered, so an exemption would let another
    | site point somebody's alerts at an attacker's endpoint — see the note in
    | public/sw.js about why the service worker does not re-register.
    */
    Route::get('/account/push/key', [PushSubscriptionController::class, 'key'])->name('push.key');
    Route::post('/account/push/subscribe', [PushSubscriptionController::class, 'store'])
        ->middleware('throttle:30,1')->name('push.subscribe');
    Route::post('/account/push/unsubscribe', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');
    Route::delete('/account/push/devices/{subscription}', [PushSubscriptionController::class, 'forget'])
        ->name('push.forget');

    Route::get('/verify', [VerificationController::class, 'show'])->name('verify.show');
    Route::post('/verify', [VerificationController::class, 'store'])
        ->middleware('throttle:5,10')       // identity checks cost money per call
        ->name('verify.store');

    /*
    | Moderation console (M12). Staff only — 404 to everyone else, so the
    | existence of the console is not confirmed to ordinary accounts.
    */
    Route::middleware('staff:moderator')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', AdminDashboardController::class)->name('dashboard');

        Route::get('/listings', [ListingAdminController::class, 'index'])->name('listings');
        Route::get('/people', [UserAdminController::class, 'index'])->name('users');
        Route::put('/people/{user}/verification', [UserAdminController::class, 'updateVerification'])->name('users.verify');

        Route::get('/orders', [OrderAdminController::class, 'index'])->name('orders');

        /*
        | Money (M11). Admin rather than moderator: refunding and reading the
        | bank position are finance decisions, and the moderation role exists to
        | let people review listings without also handing them the till.
        */
        Route::middleware('staff:admin')->group(function () {
            Route::post('/orders/{order}/refund', [OrderAdminController::class, 'refund'])->name('orders.refund');
            Route::post('/refunds/{refund}/approve', [OrderAdminController::class, 'approveRefund'])->name('refunds.approve');
            Route::post('/refunds/{refund}/cancel', [OrderAdminController::class, 'cancelRefund'])->name('refunds.cancel');

            /*
            | Payouts (FR-M11-07). The one place money leaves to a destination
            | somebody chose, so every action here is admin-only and approval is
            | always by a second person — there is no threshold that skips it.
            */
            Route::get('/payouts', [PayoutAdminController::class, 'index'])->name('payouts');
            Route::post('/payouts/credit', [PayoutAdminController::class, 'credit'])->name('payouts.credit');
            Route::post('/payouts/{payout}/approve', [PayoutAdminController::class, 'approve'])->name('payouts.approve');
            Route::post('/payouts/{payout}/cancel', [PayoutAdminController::class, 'cancel'])->name('payouts.cancel');
            Route::post('/payouts/{payout}/return', [PayoutAdminController::class, 'returnToLedger'])->name('payouts.return');
            Route::post('/payout-accounts/{account}/approve', [PayoutAdminController::class, 'approveAccount'])->name('payout-accounts.approve');

            Route::get('/settlements', [SettlementAdminController::class, 'index'])->name('settlements');
            Route::post('/settlements/reconcile', [SettlementAdminController::class, 'reconcile'])
                // The provider's API is the expensive part, not ours.
                ->middleware('throttle:6,1')->name('settlements.reconcile');
            Route::get('/settlements/{settlement}', [SettlementAdminController::class, 'show'])->name('settlements.show');
            Route::post('/settlement-transactions/{transaction}/confirm', [SettlementAdminController::class, 'confirmTransaction'])
                ->name('settlements.confirm');
        });

        /*
        | Amenities and areas (FR-M12-06). Moderator-level: adding "Borehole" to
        | the amenity list is an editorial decision, not a financial one.
        */
        Route::get('/taxonomy', [TaxonomyController::class, 'index'])->name('taxonomy');
        Route::post('/taxonomy/amenities', [TaxonomyController::class, 'storeAmenity'])->name('amenities.store');
        Route::put('/taxonomy/amenities/{amenity}', [TaxonomyController::class, 'updateAmenity'])->name('amenities.update');
        Route::post('/taxonomy/amenities/{amenity}/merge', [TaxonomyController::class, 'mergeAmenity'])->name('amenities.merge');
        Route::delete('/taxonomy/amenities/{amenity}', [TaxonomyController::class, 'destroyAmenity'])->name('amenities.destroy');
        Route::post('/taxonomy/areas', [TaxonomyController::class, 'storeArea'])->name('areas.store');
        Route::put('/taxonomy/areas/{area}', [TaxonomyController::class, 'updateArea'])->name('areas.update');
        Route::post('/taxonomy/areas/{area}/merge', [TaxonomyController::class, 'mergeArea'])->name('areas.merge');
        Route::delete('/taxonomy/areas/{area}', [TaxonomyController::class, 'destroyArea'])->name('areas.destroy');

        Route::get('/operations', [OperationsController::class, 'index'])->name('operations');
        Route::put('/areas/{area}/coverage', [OperationsController::class, 'toggleCoverage'])->name('areas.coverage');
        Route::post('/areas/{area}/slots', [OperationsController::class, 'addSlots'])->name('areas.slots');

        // FR-M13-02/03: where people fall out of each funnel. Moderator-level,
        // like the rest of the reporting — it carries no money and no personal
        // detail, only counts.
        Route::get('/analytics', AdminAnalyticsController::class)->name('analytics');

        Route::get('/audit', [AuditController::class, 'index'])->name('audit');

        Route::get('/queue', [ModerationController::class, 'queue'])->name('queue');
        Route::get('/queue/{property}', [ModerationController::class, 'review'])->name('review');
        Route::post('/queue/{property}/approve', [ModerationController::class, 'approve'])->name('approve');
        Route::post('/queue/{property}/reject', [ModerationController::class, 'reject'])->name('reject');
        Route::post('/queue/{property}/unpublish', [ModerationController::class, 'unpublish'])->name('unpublish');
    });

    Route::prefix('dashboard')->name('lister.')->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        Route::get('/listings/create', [ListingController::class, 'create'])->name('listings.create');
        Route::post('/listings', [ListingController::class, 'store'])->name('listings.store');
        // FR-M13-01. Behind the same policy as editing: how a listing is
        // performing is commercially sensitive to the lister.
        Route::get('/listings/{property}/analytics', ListingAnalyticsController::class)
            ->name('listings.analytics');

        Route::get('/listings/{property}/edit', [ListingController::class, 'edit'])->name('listings.edit');
        Route::put('/listings/{property}', [ListingController::class, 'update'])->name('listings.update');
        Route::post('/listings/{property}/submit', [ListingController::class, 'submit'])->name('listings.submit');

        // Media (M3). Throttled: image processing is CPU-bound, and an
        // unthrottled upload endpoint is a cheap way to exhaust a small VPS.
        Route::middleware('throttle:30,1')->group(function () {
            Route::post('/listings/{property}/photos', [MediaController::class, 'storePhotos'])->name('media.photos');
            Route::post('/listings/{property}/video', [MediaController::class, 'storeVideo'])->name('media.video');
        });
        Route::post('/listings/{property}/media/{media}/cover', [MediaController::class, 'setCover'])->name('media.cover');
        Route::post('/listings/{property}/media/reorder', [MediaController::class, 'reorder'])->name('media.reorder');
        Route::delete('/listings/{property}/media/{media}', [MediaController::class, 'destroy'])->name('media.destroy');

        /*
        | Being paid (FR-M11-07). A lister asks; an admin approves. Self-service
        | in both directions would make a stolen login worth the balance.
        */
        Route::get('/payouts', [PayoutController::class, 'index'])->name('payouts');
        Route::post('/payouts/account', [PayoutController::class, 'storeAccount'])
            ->middleware('throttle:6,1')->name('payouts.account');
        Route::post('/payouts/request', [PayoutController::class, 'requestPayout'])
            ->middleware('throttle:10,1')->name('payouts.request');
    });

    /*
    | 3D capture purchase and scheduling (M4).
    */
    Route::name('scan.')->group(function () {
        Route::get('/dashboard/listings/{property}/3d', [ScanController::class, 'show'])->name('offer');
        Route::post('/dashboard/listings/{property}/3d', [ScanController::class, 'checkout'])
            ->middleware('throttle:10,1')->name('checkout');
        Route::get('/orders/{order}/return', [ScanController::class, 'returned'])->name('return');
        Route::get('/orders/{order}/schedule', [ScanController::class, 'schedule'])->name('schedule');
        Route::post('/orders/{order}/schedule', [ScanController::class, 'book'])->name('book');

        // Stands in for the hosted payment form in local development only.
        Route::get('/orders/{order}/sandbox', SandboxCheckoutController::class)
            ->name('sandbox');
    });

    /*
    | RealSure Officer console (FR-M6-01 to FR-M6-03).
    |
    | Its own gate, outside the /admin group, for a reason that was a live bug
    | until now: the admin area is gated on `staff:moderator`, and a RealSure
    | Officer is not a moderator, so an account with that role got a 404 on
    | every screen in the console — including the one named after their job.
    | Granting a trust badge is also not a moderation decision: the moderator
    | decides whether a listing may be published, the officer decides what
    | Agentpro is willing to assert about it, and those are different powers
    | that should not imply one another.
    */
    /*
    | Path is /officer, not /realsure: the product name belongs to the public
    | page seekers and listers are sent to, and an internal console should never
    | hold a URL the marketing site needs. The route names stay `realsure.*`
    | because they describe the records being managed, while the path describes
    | who the screens are for — the same split as /technician.
    */
    Route::middleware('staff:realsure_officer')->prefix('officer')->name('realsure.')->group(function () {
        Route::get('/', [RealsureConsoleController::class, 'index'])->name('queue');
        Route::get('/{property}', [RealsureConsoleController::class, 'show'])->name('record');
        Route::post('/{property}/component', [RealsureConsoleController::class, 'record'])->name('component');
        Route::post('/{property}/grant', [RealsureConsoleController::class, 'grant'])->name('grant');
        Route::post('/{property}/revoke', [RealsureConsoleController::class, 'revoke'])->name('revoke');
    });

    /*
    | Field tool for capture technicians (FR-M4-09).
    */
    Route::middleware('staff:technician')->prefix('technician')->name('technician.')->group(function () {
        Route::get('/assignments', [AssignmentController::class, 'index'])->name('assignments');
        Route::post('/assignments/{job}/attend', [AssignmentController::class, 'attend'])->name('attend');
        Route::post('/assignments/{job}/capture', [AssignmentController::class, 'capture'])->name('capture');
    });
});
