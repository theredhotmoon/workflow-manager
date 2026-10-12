<?php

namespace App\Providers;

use App\Http\Controllers\HealthController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            // Readiness probe (container healthcheck, CI). Outside the api group on purpose: no
            // session and no Sanctum, and its own generous per-IP limit instead of the per-user
            // API one, so a probe every few seconds stays cheap (HealthController).
            Route::get('api/health', HealthController::class)->middleware('throttle:health')->name('health');

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(config('app.api_rate_limit'))->by($request->user()?->id ?: $request->ip());
        });

        // /api/health is public and opens a database connection: 60 a minute per client IP. The
        // container healthcheck (every 10 s) and CI's wait (every 2 s) stay far below it.
        RateLimiter::for('health', function (Request $request) {
            return Limit::perMinute(60)->by('health:'.$request->ip());
        });
    }
}
