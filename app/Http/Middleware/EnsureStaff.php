<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate on the whole admin area (SEC-03).
 *
 * A 404 rather than a 403: the existence of the moderation console is not
 * something an ordinary account needs confirmed.
 */
class EnsureStaff
{
    public function handle(Request $request, Closure $next, ?string $role = null): Response
    {
        $user = $request->user();

        abort_unless($user && $user->isStaff($role), 404);

        return $next($request);
    }
}
