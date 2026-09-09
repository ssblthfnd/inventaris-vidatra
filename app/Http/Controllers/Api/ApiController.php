<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

/**
 * Shared base for the read API controllers (Tahap 5.3).
 *
 * Intentionally tiny — its only job is one helper used by every `?q=` search so LIKE
 * wildcards in user input are treated literally. Everything else stays in Laravel's
 * standard controller / FormRequest / Resource layers.
 */
abstract class ApiController extends Controller
{
    /**
     * Escape `%`, `_` and `\` so a search term is matched literally inside a `LIKE`.
     * The term itself is always passed as a bound parameter — this only affects
     * wildcard semantics, not SQL safety.
     */
    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
