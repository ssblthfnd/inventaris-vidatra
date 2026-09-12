<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tahap 6.6 (M-2) — `config/cors.php`. Before this stage there was no
 * published CORS config at all, so Laravel's framework default
 * (`allowed_origins => ['*']`) applied — confirmed live during the Stage 6.6
 * Pass 1 audit even for a spoofed `Origin: http://evil-attacker.test`.
 *
 * `.env.testing` sets `SANCTUM_STATEFUL_DOMAINS=localhost`, so
 * `config/cors.php`'s derived `allowed_origins` is exactly
 * `['http://localhost', 'https://localhost']` for this test run.
 */
class CorsPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_trusted_origin_is_echoed_back_not_wildcarded(): void
    {
        $response = $this->withHeader('Origin', 'http://localhost')->getJson('/api/me');

        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost');
    }

    public function test_untrusted_origin_receives_no_allow_origin_header(): void
    {
        $response = $this->withHeader('Origin', 'http://evil-attacker.test')->getJson('/api/me');

        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_wildcard_is_never_sent_for_any_origin(): void
    {
        foreach (['http://localhost', 'http://evil-attacker.test', 'null'] as $origin) {
            $response = $this->withHeader('Origin', $origin)->getJson('/api/me');

            $this->assertNotSame(
                '*',
                $response->headers->get('Access-Control-Allow-Origin'),
                "Wildcard CORS origin was sent for Origin [{$origin}]."
            );
        }
    }

    public function test_credentials_are_only_supported_for_the_trusted_origin(): void
    {
        $trusted = $this->withHeader('Origin', 'http://localhost')->getJson('/api/me');
        $trusted->assertHeader('Access-Control-Allow-Credentials', 'true');

        $untrusted = $this->withHeader('Origin', 'http://evil-attacker.test')->getJson('/api/me');
        $untrusted->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    public function test_a_request_with_no_origin_header_is_unaffected(): void
    {
        // Same-origin requests (the SPA's normal case) never send an Origin
        // header for a same-origin fetch — CORS headers are simply irrelevant
        // to them either way, but this confirms the app still responds normally.
        $this->getJson('/api/me')->assertStatus(401);
    }
}
