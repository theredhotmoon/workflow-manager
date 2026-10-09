<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as Middleware;

/**
 * CSRF protection (Laravel 13's PreventRequestForgery: a same-origin Sec-Fetch-Site header or a
 * matching XSRF token). Keeps its old name so the HTTP Kernel and config/sanctum.php are unchanged.
 */
class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        //
    ];
}
