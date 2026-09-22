<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // Het abonnementen-endpoint van de pushberichten, zie routes/api.php.
        RateLimiter::for('push', fn (Request $request): Limit => Limit::perMinute((int) config('push.max_per_minute'))->by($request->ip()));
    }
}
