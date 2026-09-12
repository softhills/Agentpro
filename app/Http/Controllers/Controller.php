<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

/**
 * Laravel 11 removed these traits from the generated base controller, so
 * $this->authorize() is not available unless a controller opts in. Adding them
 * here rather than per-controller means an authorisation call can never be
 * silently unavailable in a controller that needs one (SEC-03).
 */
abstract class Controller
{
    use AuthorizesRequests, ValidatesRequests;
}
