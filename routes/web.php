<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/search', [SearchController::class, 'index'])->name('search');

// Rate limited: the pin endpoint is the cheapest way to scrape the whole
// inventory, so it is capped per IP (SEC-10).
Route::get('/search/pins', [SearchController::class, 'pins'])
    ->middleware('throttle:60,1')
    ->name('search.pins');

Route::get('/property/{property}', [PropertyController::class, 'show'])->name('property.show');
