<?php

namespace App\Providers;

use App\Services\ProcedureHtmlSanitizer;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // One purifier per process: building its HTML definition is the expensive part.
        $this->app->singleton(ProcedureHtmlSanitizer::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
