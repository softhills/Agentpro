<?php

use App\Http\Controllers\Admin\ModerationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\VerificationController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Lister\DashboardController;
use App\Http\Controllers\Lister\ListingController;
use App\Http\Controllers\Lister\MediaController;
use App\Http\Controllers\Lister\SandboxCheckoutController;
use App\Http\Controllers\Lister\ScanController;
use App\Http\Controllers\Technician\AssignmentController;
use App\Http\Controllers\Webhooks\PaystackWebhookController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

/*
| Public — no account required (FR-M1-01).
*/
Route::get('/', HomeController::class)->name('home');
Route::get('/search', [SearchController::class, 'index'])->name('search');

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
    Route::get('/verify', [VerificationController::class, 'show'])->name('verify.show');
    Route::post('/verify', [VerificationController::class, 'store'])
        ->middleware('throttle:5,10')       // identity checks cost money per call
        ->name('verify.store');

    /*
    | Moderation console (M12). Staff only — 404 to everyone else, so the
    | existence of the console is not confirmed to ordinary accounts.
    */
    Route::middleware('staff:moderator')->prefix('admin')->name('admin.')->group(function () {
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
