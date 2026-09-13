<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'staff' => \App\Http\Middleware\EnsureStaff::class,
        ]);

        /*
         * The two cookie-consent cookies are sent in the clear (FR-M1-02).
         *
         * Three reasons, and the first is the one that decided it: a person
         * should be able to open their browser's cookie inspector and read
         * their own privacy choice. A consent record only they cannot read is a
         * poor kind of consent record.
         *
         * Neither carries anything worth protecting. The choice is one of two
         * public words, and the visitor id is a random token that means nothing
         * without the server — it is not a credential, grants no access, and
         * identifies nobody outside our own event table. Encrypting them would
         * also take each from a handful of bytes to roughly three hundred, on
         * every request, on a platform written for a metered 4G connection
         * (NFR-02).
         *
         * Tampering buys nothing: an invented visitor id is refused unless it
         * matches the expected shape, and setting one is no different from
         * being issued one.
         */
        $middleware->encryptCookies(except: [
            \App\Support\Consent::COOKIE,
            \App\Support\Consent::VISITOR_COOKIE,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
