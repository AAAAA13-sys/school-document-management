<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Pagination\Paginator::useBootstrapFive();
        \Illuminate\Support\Facades\RateLimiter::for('integration', fn ($request) =>
            \Illuminate\Cache\RateLimiting\Limit::perMinute(120)
                ->by('connector:'.$request->attributes->get('integration')->id));
    }
}
