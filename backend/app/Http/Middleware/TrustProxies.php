<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The headers that should be used to detect proxies.
     *
     * Only the client IP and scheme: the frontend Nginx sets X-Forwarded-For (appending the
     * connecting address) and X-Forwarded-Proto, but passes a client's X-Forwarded-Host/-Port
     * through untouched, so those would let a client choose the host Laravel builds URLs with.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_PROTO;

    /**
     * The trusted proxies: config('app.trusted_proxies') (env TRUSTED_PROXIES, default the
     * private and loopback ranges the compose network uses).
     *
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        return config('app.trusted_proxies');
    }
}
