<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        RateLimiter::for('login', static function (Request $request): Limit {
            $email = Str::transliterate($request->input('email', ''));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        ResetPassword::createUrlUsing(static function (User $user, string $token): string {
            $query = http_build_query(['email' => $user->getEmailForPasswordReset()]);

            return rtrim((string) config('app.frontend_url'), '/')
                .'/reset-password/'.$token.'?'.$query;
        });
    }
}
