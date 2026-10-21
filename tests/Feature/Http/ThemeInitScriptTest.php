<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * Light/dark theme bootstrap in the SPA shell (resources/views/welcome.blade.php).
 *
 * `public/theme-init.js` must run before the app renders (no white flash) and
 * must be an external file: the strict CSP (`script-src 'self'`) forbids inline
 * scripts. These tests pin both, plus the cache-busting `?v=` version and that
 * the script and `resources/js/lib/theme.js` agree on the storage key.
 */
class ThemeInitScriptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_shell_loads_the_theme_script_in_head_with_a_file_version(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $version = filemtime(public_path('theme-init.js'));
        $tag = '<script src="'.asset('theme-init.js').'?v='.$version.'"></script>';

        $this->assertStringContainsString($tag, $html);
        $this->assertLessThan(strpos($html, '</head>'), strpos($html, $tag), 'theme-init.js must load in <head>.');
    }

    public function test_shell_has_no_inline_script(): void
    {
        foreach (['/', '/login', '/dashboard'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            preg_match_all('/<script\b[^>]*>/i', $html, $tags);
            $this->assertNotEmpty($tags[0], "{$path}: expected the theme script tag.");
            foreach ($tags[0] as $tag) {
                $this->assertMatchesRegularExpression('/\ssrc="[^"]+"/', $tag, "{$path}: inline script found: {$tag}");
            }
        }
    }

    public function test_shell_declares_both_color_schemes(): void
    {
        $this->get('/')->assertSee('<meta name="color-scheme" content="light dark">', false);
    }

    public function test_csp_is_unchanged_by_the_theme_script(): void
    {
        $this->get('/')->assertHeader(
            'Content-Security-Policy',
            "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
            ."img-src 'self' data:; font-src 'self' data:; connect-src 'self'; object-src 'none'; "
            ."base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
        );
    }

    public function test_init_script_and_frontend_module_share_the_storage_key(): void
    {
        preg_match("/THEME_STORAGE_KEY = '([^']+)'/", file_get_contents(resource_path('js/lib/theme.js')), $m);
        $this->assertNotEmpty($m[1] ?? null, 'THEME_STORAGE_KEY not found in lib/theme.js.');

        $this->assertStringContainsString(
            "getItem('{$m[1]}')",
            file_get_contents(public_path('theme-init.js')),
        );
    }
}
