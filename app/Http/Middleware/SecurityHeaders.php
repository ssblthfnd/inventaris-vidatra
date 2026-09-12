<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds baseline security response headers to every request (Tahap 6.6, M-1 / M-4).
 * Global middleware (see `bootstrap/app.php`) — applies to the SPA shell (`web`)
 * and the JSON API (`api`) alike, since both are equally worth protecting and
 * neither has a reason to opt out.
 *
 * Deliberately conservative: every header here was chosen because the Stage 6.6
 * Pass 1 audit inspected the ACTUAL application (no inline `<script>`, no
 * external CDN scripts, self-hosted fonts via `laravel-vite-plugin`'s `bunny()`
 * helper, exactly one component using an inline `style=""` attribute for
 * viewport-clamped positioning) rather than applying a generic template — see
 * each header's own comment for why its exact value is safe for this app.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Stops the browser guessing a response's MIME type from its content —
        // no legitimate reason for this app to want that.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Clickjacking: this SPA has no legitimate reason to ever be framed by
        // another site (or even by itself) — DENY, not SAMEORIGIN.
        $response->headers->set('X-Frame-Options', 'DENY');

        // Sends the full referrer only same-origin; cross-origin gets just the
        // origin (not the full path/query) — a reasonable, non-breaking default
        // that still lets analytics-free, purely internal navigation work.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Conservative allow-list: this app never uses the camera, microphone,
        // geolocation, USB, or Payment Request API, so disabling them costs
        // nothing and removes attack surface a compromised/embedded script
        // could otherwise reach for.
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=()'
        );

        $this->applyContentSecurityPolicy($request, $response);

        // HSTS only over an actually-secure connection — never forces HTTPS on
        // plain-HTTP localhost, which would just break local development.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // M-4: best-effort application-level removal. `expose_php=Off` in the
        // deployed php.ini remains the more complete fix (it also covers PHP's
        // own error pages before Laravel ever boots) — see the Stage 6.6 report.
        header_remove('X-Powered-By');
        $response->headers->remove('X-Powered-By');

        return $response;
    }

    /**
     * A CSP is only added when we can be confident it will not break the app.
     * `public/hot` is the EXACT file Laravel's own `@vite()` Blade directive
     * checks to decide "the Vite dev server is running" (no new detection
     * mechanism invented here) — when it's present, `npm run dev`'s HMR client
     * loads a module script from the dev server's own origin/port and opens a
     * WebSocket for hot-reload, which a locked-down `script-src`/`connect-src`
     * would break. Rather than trying to discover and allow-list that
     * ever-changing dev-server origin, we simply skip CSP in that mode —
     * exactly the "don't introduce a brittle policy" instruction from the
     * audit. The production BUILD (`npm run build`, served from `public/build`
     * with no dev server) always gets the real policy.
     */
    private function applyContentSecurityPolicy(Request $request, Response $response): void
    {
        if (file_exists(public_path('hot'))) {
            return;
        }

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            // 'unsafe-inline' here only: MultiSelectFilter.jsx sets an inline
            // `style` prop for its viewport-clamped dropdown position — the ONE
            // inline-style user in the whole codebase (grep-verified). Scripts
            // stay strict; inline STYLE is a far smaller blast radius to accept.
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]));
    }
}
