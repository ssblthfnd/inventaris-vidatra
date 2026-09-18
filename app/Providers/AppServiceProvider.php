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

        // Tahap 6.7.1 — S67-10: refuse to boot production with LOG_LEVEL=debug.
        DeploymentSafety::assertLogLevelSafety((string) config('app.env'), (string) config('logging.level'));

        $this->configureLoginRateLimiter();
        $this->configureQrRedirectRateLimiter();
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

    /**
     * `GET /a/{asset}` rate limiting (Tahap 6.9 R8.2, P3-2). The route is
     * deliberately public (printed QR labels must resolve pre-login — see
     * {@see \App\Http\Controllers\AssetQrRedirectController}) and returns no
     * asset data, only a 302/404 based on whether the id exists. Without any
     * limiter, an unauthenticated caller could script sequential requests to
     * infer roughly how many assets exist. One IP-based limit is enough here
     * (unlike login, there is no separate credential/account dimension to
     * protect against) — generous enough that a person physically scanning
     * several printed labels in a row, or a shared-NAT office of scanners,
     * never trips it, but bounded enough to blunt bulk enumeration.
     */
    private function configureQrRedirectRateLimiter(): void
    {
        RateLimiter::for('qr-redirect', function (Request $request) {
            return Limit::perMinute(60)->by('qr-redirect:ip:'.(string) $request->ip());
        });
    }
}
