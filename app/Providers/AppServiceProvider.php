<?php

namespace App\Providers;

use App\Support\DeploymentSafety;
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
        // Tahap 6.6 — refuse to boot with a dangerous production config (H-2).
        // No-op for local/testing/staging; see DeploymentSafety's own docblock.
        DeploymentSafety::assertDebugSafety((string) config('app.env'), (bool) config('app.debug'));

        $this->configureLoginRateLimiter();
    }

    /**
     * `POST /api/login` rate limiting (Tahap 6.6, H-1). TWO simultaneous limits —
     * `RateLimiter::for()` supports returning an array of `Limit`s, and a request
     * is throttled the moment EITHER is exceeded:
     *
     *  - per (normalized email + IP): stops one attacker from brute-forcing ONE
     *    known account without also locking that account out for everyone else
     *    hitting it from a DIFFERENT IP (the legitimate owner, e.g.) — the two
     *    are on separate limiter keys.
     *  - per IP alone (any email): stops one attacker from working through many
     *    different email guesses from the same IP to route around the first
     *    limit entirely.
     *
     * Neither key contains the password or any other sensitive value — only a
     * normalized (lower-cased, trimmed) email and the request IP, matching
     * Laravel's own historical `ThrottlesLogins` convention.
     */
    private function configureLoginRateLimiter(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');
            $email = is_string($email) ? Str::lower(trim($email)) : '';
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)->by('login:email-ip:'.$email.'|'.$ip),
                Limit::perMinute(20)->by('login:ip:'.$ip),
            ];
        });
    }
}
