<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Where a browser that isn't signed in is sent. API routes never redirect: they answer 401 JSON
     * whatever the Accept header (see Handler::shouldReturnJson). There is no named 'login' route:
     * the SPA's /login screen is the shell's own route.
     *
     * @param  Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        if ($request->expectsJson() || $request->is('api', 'api/*')) {
            return null;
        }

        return url('/login');
    }
}
