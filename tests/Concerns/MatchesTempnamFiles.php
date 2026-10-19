<?php

namespace Tests\Concerns;

/**
 * A glob that really matches what `tempnam(sys_get_temp_dir(), $prefix)` creates on
 * THIS platform — so a "no temp file left behind" before/after comparison can
 * never pass vacuously. Windows keeps only the first three prefix characters
 * (`asset-export-` → `ass1A2B.tmp`), so a glob on the full prefix matches nothing
 * there and `[] === []` would prove nothing.
 *
 * The pattern is derived from one probe file made with the same call and is
 * verified to see that probe before it is returned; the probe (and only it) is
 * removed again.
 */
trait MatchesTempnamFiles
{
    protected function tempnamGlob(string $prefix): string
    {
        $probe = tempnam(sys_get_temp_dir(), $prefix);
        $this->assertIsString($probe, "tempnam() failed for prefix [{$prefix}].");

        try {
            $name = basename($probe);
            $kept = '';
            for ($length = strlen($prefix); $length > 0; $length--) {
                if (str_starts_with($name, substr($prefix, 0, $length))) {
                    $kept = substr($prefix, 0, $length);
                    break;
                }
            }
            $this->assertNotSame('', $kept, "tempnam() kept none of the prefix [{$prefix}] in [{$name}].");

            $pattern = dirname($probe).DIRECTORY_SEPARATOR.$kept.'*'.(str_ends_with($name, '.tmp') ? '.tmp' : '');
            $this->assertContains($probe, glob($pattern) ?: [], "Glob [{$pattern}] does not see tempnam()'s own file [{$probe}].");

            return $pattern;
        } finally {
            @unlink($probe);
        }
    }
}
