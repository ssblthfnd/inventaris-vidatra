<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Foto Aset R2 — the private `local` disk is NOT served (`'serve' => false` in
 * config/filesystems.php). With serving on, Laravel registers
 * `GET /storage/{path}` (`storage.local`) and `PUT /storage/{path}`
 * (`storage.local.upload`); the PUT one writes the raw request body to the
 * private disk for anyone holding a valid signature, i.e. a second way into
 * `asset-photos/` that bypasses ImageSanitizer. Nothing in the app signs URLs
 * for this disk, so neither route may exist.
 *
 * No database is touched here; the disk is `Storage::fake('local')`.
 */
class PrivateStorageServeRouteTest extends TestCase
{
    public function test_private_disk_serve_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('storage.local.upload'));
        $this->assertFalse(Route::has('storage.local'));
    }

    /**
     * Even with a signature that the framework's ReceiveFile would accept (made
     * with the app key, as `?upload=1` + relative signature), the PUT reaches no
     * upload route: only the GET-only SPA catch-all matches the path, so the
     * router answers 405 and nothing is written.
     */
    public function test_put_to_storage_path_cannot_write_to_the_private_disk(): void
    {
        Storage::fake('local');

        $path = '/storage/asset-photos/1/test.jpg';
        $signature = hash_hmac('sha256', $path.'?upload=1', (string) config('app.key'));

        $this->call('PUT', $path.'?upload=1&signature='.$signature, [], [], [], [], 'not-a-sanitized-image')
            ->assertStatus(405);

        Storage::disk('local')->assertMissing('asset-photos/1/test.jpg');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
