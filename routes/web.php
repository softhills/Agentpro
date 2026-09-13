<?php

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\ListingAdminController;
use App\Http\Controllers\Admin\ModerationController;
use App\Http\Controllers\Admin\OperationsController;
use App\Http\Controllers\Admin\OrderAdminController;
use App\Http\Controllers\Admin\SettlementAdminController;
use App\Http\Controllers\Admin\UserAdminController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\VerificationController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InteractionController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\Lister\DashboardController;
use App\Http\Controllers\Lister\ListingController;
use App\Http\Controllers\Lister\MediaController;
use App\Http\Controllers\Lister\SandboxCheckoutController;
use App\Http\Controllers\Lister\ScanController;
use App\Http\Controllers\Technician\AssignmentController;
use App\Http\Controllers\Webhooks\PaystackWebhookController;
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

            Route::get('/settlements', [SettlementAdminController::class, 'index'])->name('settlements');
            Route::post('/settlements/reconcile', [SettlementAdminController::class, 'reconcile'])
                // The provider's API is the expensive part, not ours.
                ->middleware('throttle:6,1')->name('settlements.reconcile');
            Route::get('/settlements/{settlement}', [SettlementAdminController::class, 'show'])->name('settlements.show');
            Route::post('/settlement-transactions/{transaction}/confirm', [SettlementAdminController::class, 'confirmTransaction'])
                ->name('settlements.confirm');
        });

        Route::get('/operations', [OperationsController::class, 'index'])->name('operations');
        Route::put('/areas/{area}/coverage', [OperationsController::class, 'toggleCoverage'])->name('areas.coverage');
        Route::post('/areas/{area}/slots', [OperationsController::class, 'addSlots'])->name('areas.slots');

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
    | Field tool for capture technicians (FR-M4-09).
    */
    Route::middleware('staff:technician')->prefix('technician')->name('technician.')->group(function () {
        Route::get('/assignments', [AssignmentController::class, 'index'])->name('assignments');
        Route::post('/assignments/{job}/attend', [AssignmentController::class, 'attend'])->name('attend');
        Route::post('/assignments/{job}/capture', [AssignmentController::class, 'capture'])->name('capture');
    });
});
