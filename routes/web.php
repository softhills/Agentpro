<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\VerificationController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Lister\DashboardController;
use App\Http\Controllers\Lister\ListingController;
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

    Route::prefix('dashboard')->name('lister.')->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        Route::get('/listings/create', [ListingController::class, 'create'])->name('listings.create');
        Route::post('/listings', [ListingController::class, 'store'])->name('listings.store');
        Route::get('/listings/{property}/edit', [ListingController::class, 'edit'])->name('listings.edit');
        Route::put('/listings/{property}', [ListingController::class, 'update'])->name('listings.update');
        Route::post('/listings/{property}/submit', [ListingController::class, 'submit'])->name('listings.submit');
    });
});
