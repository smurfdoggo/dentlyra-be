<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $email = $request->input('email');

            return [
                Limit::perMinute(30)->by('ip:'.$request->ip()),
                Limit::perMinute(5)->by('login:'.hash('sha256', (is_string($email) ? strtolower($email) : '').'|'.$request->ip())),
            ];
        });

        RateLimiter::for('registration', fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip()));
    }
}
