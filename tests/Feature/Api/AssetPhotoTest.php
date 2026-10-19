<?php

namespace Tests\Feature\Api;

use App\Enums\MutationEventType;
use App\Enums\UserRole;
use App\Import\ImportManager;
use App\Models\Asset;
use App\Models\AssetPhoto;
use App\Models\Category;
use App\Models\Location;
use App\Models\MutationLog;
use App\Models\Room;
use App\Models\Subcategory;
use App\Models\User;
use App\Services\Asset\AssetMutationRecorder;
use App\Services\Image\ImageSanitizer;
use App\Services\Image\SanitizedImage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\BuildsTestImages;
use Tests\Concerns\InteractsWithImportFixtures;
use Tests\TestCase;

/**
 * Foto Aset — upload / list / image / delete, the three-photo limit and its
 * concurrency guards, DB↔file consistency, history (PHOTO_ADDED/PHOTO_DELETED,
 * never revertable) and that the asset lifecycle, list, export, label and import
 * are unaffected. Authorization / IDOR is {@see AssetPhotoAuthorizationTest}.
 *
 * Storage is `Storage::fake('local')` — the real storage/app/private is never
 * written by these tests.
 */
class AssetPhotoTest extends TestCase
{
    use BuildsTestImages;
    use InteractsWithImportFixtures;
    use RefreshDatabase;

    private Subcategory $meja;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        foreach (['01', '02', '03', '04'] as $code) {
            Location::query()->firstOrCreate(['code' => $code], ['name' => "Lokasi {$code}", 'is_active' => true]);
        }
        Category::query()->firstOrCreate(['code' => '02'], ['name' => 'Meubelair', 'is_active' => true]);
        $this->meja = Subcategory::query()->firstOrCreate(['category_code' => '02', 'code' => '001'], ['name' => 'Meja', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        AssetPhoto::flushEventListeners();
        $this->cleanUpTestImages();
        parent::tearDown();
    }

    /* ================================================================== helpers */

    private function operator(): User
    {
        return User::factory()->create(['role' => UserRole::Operator]);
    }

    private function asset(string $location = '02', array $overrides = []): Asset
    {
        return Asset::factory()->forSubcategory($this->meja)->create(array_merge(['location_code' => $location], $overrides));
    }

    private function upload(Asset $asset, ?UploadedFile $file = null): TestResponse
    {
        return $this->post("/api/assets/{$asset->id}/photos", ['photo' => $file ?? $this->uploadedImage($this->jpegBytes(400, 300))], ['Accept' => 'application/json']);
    }

    /** @return list<string> */
    private function storedFiles(): array
    {
        return Storage::disk('local')->allFiles('asset-photos');
    }

    /* ================================================================== upload & list */

    public function test_asset_without_photos_lists_none(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());

        $this->getJson("/api/assets/{$asset->id}/photos")->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.max_photos', 3)
            ->assertJsonPath('meta.max_upload_kb', 15360)
            ->assertJsonPath('meta.output_max_side', 1600);
    }

    public function test_upload_stores_a_reencoded_jpeg_under_a_uuid_name_and_returns_metadata_only(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($user = $this->operator());

        $response = $this->upload($asset, $this->uploadedImage($this->withExif($this->jpegBytes(2400, 1200)), 'IMG_20261016_rahasia.jpg'))
            ->assertCreated()
            ->assertJsonPath('data.slot', 1)
            ->assertJsonPath('data.mime', 'image/jpeg')
            ->assertJsonPath('data.width', 1600)
            ->assertJsonPath('data.height', 800)
            ->assertJsonPath('message', 'Foto slot 1 berhasil ditambahkan.')
            ->assertJsonMissingPath('data.path');

        $photo = AssetPhoto::query()->sole();
        $response->assertJsonPath('data.id', $photo->id)
            ->assertJsonPath('data.url', "/api/assets/{$asset->id}/photos/{$photo->id}")
            ->assertJsonPath('data.size_bytes', $photo->size_bytes);
        $this->assertStringNotContainsString('asset-photos', $response->getContent());
        $this->assertStringNotContainsString('rahasia', $response->getContent());

        $this->assertMatchesRegularExpression("#^asset-photos/{$asset->id}/[0-9a-f-]{36}\\.jpg$#", $photo->path);
        $this->assertSame($user->id, $photo->uploaded_by);
        $this->assertSame([$photo->path], $this->storedFiles());

        $stored = Storage::disk('local')->get($photo->path);
        $this->assertSame(strlen($stored), $photo->size_bytes);
        $this->assertStringStartsWith("\xFF\xD8", $stored);
        $this->assertStringNotContainsString('SECRETCAM', $stored);
        $this->assertStringNotContainsString('Exif', $stored);
    }

    public function test_a_png_upload_is_stored_as_jpeg(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());

        $this->upload($asset, $this->uploadedImage($this->transparentPngBytes(), 'gambar.png', 'image/png'))
            ->assertCreated()->assertJsonPath('data.mime', 'image/jpeg');

        $this->assertStringEndsWith('.jpg', AssetPhoto::query()->sole()->path);
    }

    public function test_one_photo_then_three_photos_fill_slots_in_order(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());

        $this->upload($asset)->assertCreated()->assertJsonPath('data.slot', 1);
        $this->getJson("/api/assets/{$asset->id}/photos")->assertOk()->assertJsonCount(1, 'data');

        $this->upload($asset)->assertCreated()->assertJsonPath('data.slot', 2);
        $this->upload($asset)->assertCreated()->assertJsonPath('data.slot', 3);

        $list = $this->getJson("/api/assets/{$asset->id}/photos")->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame([1, 2, 3], array_column($list->json('data'), 'slot'));
        $this->assertCount(3, $this->storedFiles());
    }

    public function test_fourth_upload_is_refused_with_a_clear_message_and_writes_nothing(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());
        foreach ([1, 2, 3] as $_) {
            $this->upload($asset)->assertCreated();
        }
        $before = $this->storedFiles();

        $this->upload($asset)->assertUnprocessable()
            ->assertJsonPath('errors.photo.0', 'Aset ini sudah memiliki 3 foto (batas maksimal). Hapus salah satu foto sebelum menambahkan foto baru.');

        $this->assertSame(3, AssetPhoto::query()->count());
        $this->assertSame($before, $this->storedFiles());
    }

    public function test_a_freed_slot_is_reused(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());
        foreach ([1, 2, 3] as $_) {
            $this->upload($asset)->assertCreated();
        }
        $slot2 = AssetPhoto::query()->where('slot', 2)->sole();

        $this->deleteJson("/api/assets/{$asset->id}/photos/{$slot2->id}")->assertNoContent();
        $this->upload($asset)->assertCreated()->assertJsonPath('data.slot', 2);
    }

    public function test_limit_is_rechecked_under_the_row_lock_when_a_concurrent_upload_wins(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($user = $this->operator());
        $this->upload($asset)->assertCreated();
        $this->upload($asset)->assertCreated();

        // A competing request commits the third photo after this request's early
        // check passed but before its transaction locks the asset.
        $this->app->instance(ImageSanitizer::class, new class($asset, $user) extends ImageSanitizer
        {
            public function __construct(private Asset $asset, private User $user)
            {
                parent::__construct();
            }

            public function sanitize(string $path): SanitizedImage
            {
                $result = parent::sanitize($path);
                AssetPhoto::query()->create([
                    'asset_id' => $this->asset->id, 'slot' => 3, 'path' => "asset-photos/{$this->asset->id}/concurrent.jpg",
                    'mime' => 'image/jpeg', 'size_bytes' => 1, 'width' => 1, 'height' => 1, 'uploaded_by' => $this->user->id,
                ]);

                return $result;
            }
        });
        $filesBefore = $this->storedFiles();

        $this->upload($asset)->assertUnprocessable()->assertJsonPath('errors.photo.0', fn ($m) => str_contains($m, 'sudah memiliki 3 foto'));

        $this->assertSame(3, AssetPhoto::query()->where('asset_id', $asset->id)->count());
        $this->assertSame($filesBefore, $this->storedFiles(), "the losing request's new file is removed again");
    }

    public function test_a_slot_conflict_is_a_409_not_a_500_and_removes_only_the_new_file(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());
        $this->upload($asset)->assertCreated();
        $existing = AssetPhoto::query()->sole();

        // the same slot is taken between choosing it and inserting it
        AssetPhoto::creating(function (AssetPhoto $photo): void {
            DB::table('asset_photos')->insert([
                'asset_id' => $photo->asset_id, 'slot' => $photo->slot, 'path' => "asset-photos/{$photo->asset_id}/race.jpg",
                'mime' => 'image/jpeg', 'size_bytes' => 1, 'width' => 1, 'height' => 1,
            ]);
        });

        $this->upload($asset)->assertStatus(409)
            ->assertJsonPath('message', 'Slot foto baru saja terisi oleh unggahan lain. Muat ulang daftar foto lalu coba lagi.');

        $this->assertSame([$existing->path], $this->storedFiles(), 'the existing photo file is untouched, the new one removed');
        $this->assertSame([$existing->id], AssetPhoto::query()->pluck('id')->all());
    }

    public function test_a_failed_transaction_removes_the_new_file_and_leaves_no_row_or_history(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());
        $this->upload($asset)->assertCreated();
        $existing = AssetPhoto::query()->sole();
        $logsBefore = MutationLog::query()->count();

        $recorder = Mockery::mock(AssetMutationRecorder::class)->makePartial();
        $recorder->shouldReceive('record')->andThrow(new RuntimeException('history write failed'));
        $this->app->instance(AssetMutationRecorder::class, $recorder);

        $this->upload($asset)->assertStatus(500);

        $this->assertSame([$existing->id], AssetPhoto::query()->pluck('id')->all());
        $this->assertSame([$existing->path], $this->storedFiles());
        $this->assertSame($logsBefore, MutationLog::query()->count());
    }

    public function test_a_failed_cleanup_after_a_failed_upload_is_logged_for_the_prune_command(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());

        $recorder = Mockery::mock(AssetMutationRecorder::class)->makePartial();
        $recorder->shouldReceive('record')->andThrow(new RuntimeException('history write failed'));
        $this->app->instance(AssetMutationRecorder::class, $recorder);
        $this->diskWhoseDeleteFails();
        Log::spy();

        $this->upload($asset)->assertStatus(500);

        $this->assertSame(0, AssetPhoto::query()->count());
        $this->assertCount(1, $this->storedFiles(), 'the file could not be removed and is now an orphan');
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'asset_photo.file_delete_failed'
            && $context['asset_id'] === $asset->id && ! str_contains(json_encode($context), 'asset-photos/'))->once();
    }

    public function test_storage_write_failure_is_a_controlled_error_without_a_row(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());
        $this->swapDisk(failPut: true);

        $this->upload($asset)->assertStatus(500)->assertJsonPath('message', 'Foto gagal disimpan di server. Coba lagi beberapa saat lagi.');

        $this->assertSame(0, AssetPhoto::query()->count());
        $this->assertSame(0, MutationLog::query()->where('event_type', 'PHOTO_ADDED')->count());
    }

    /* ================================================================== rejected uploads */

    public function test_rejected_images_leave_no_row_or_file_and_never_touch_existing_photos(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());
        $this->upload($asset)->assertCreated();
        $existing = AssetPhoto::query()->sole();

        $this->upload($asset, $this->uploadedImage("ini teks\n", 'foto.jpg'))->assertUnprocessable()
            ->assertJsonPath('errors.photo.0', fn ($m) => str_contains($m, 'bukan gambar yang valid'));
        $this->upload($asset, $this->uploadedImage($this->heicBytes(), 'IMG_0001.HEIC', 'image/heic'))->assertUnprocessable()
            ->assertJsonPath('errors.photo.0', fn ($m) => str_contains($m, 'HEIC/HEIF belum didukung'));
        $this->upload($asset, $this->uploadedImage($this->isoBmffBytes('avif', ['mif1', 'miaf', 'MA1B']), 'foto.avif', 'image/avif'))->assertUnprocessable()
            ->assertJsonPath('errors.photo.0', fn ($m) => str_contains($m, 'tidak didukung') && ! str_contains($m, 'HEIC'));
        $this->upload($asset, $this->uploadedImage($this->corruptPngBytes(), 'rusak.png', 'image/png'))->assertUnprocessable()
            ->assertJsonPath('errors.photo.0', fn ($m) => str_contains($m, 'tidak dapat dibaca'));
        $this->upload($asset, $this->uploadedImage($this->pngHeaderOnly(12001, 100), 'besar.png', 'image/png'))->assertUnprocessable()
            ->assertJsonPath('errors.photo.0', fn ($m) => str_contains($m, '12.000'));

        $this->assertSame([$existing->id], AssetPhoto::query()->pluck('id')->all());
        $this->assertSame([$existing->path], $this->storedFiles());
        $this->assertSame(1, MutationLog::query()->where('event_type', 'PHOTO_ADDED')->count());
    }

    public function test_request_validation_messages(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());

        $this->post("/api/assets/{$asset->id}/photos", [], ['Accept' => 'application/json'])->assertUnprocessable()
            ->assertJsonPath('errors.photo.0', fn ($m) => str_starts_with($m, 'Pilih foto yang akan diunggah.'));
        $this->post("/api/assets/{$asset->id}/photos", ['photo' => 'not-a-file'], ['Accept' => 'application/json'])->assertUnprocessable()
            ->assertJsonValidationErrors('photo');
        $this->upload($asset, UploadedFile::fake()->create('besar.jpg', 15361, 'image/jpeg'))->assertUnprocessable()
            ->assertJsonPath('errors.photo.0', 'Ukuran file foto maksimal 15 MB.');

        $this->assertSame(0, AssetPhoto::query()->count());
    }

    /* ================================================================== R6: upload size layers */

    /**
     * The application limit (`inventory.photos.max_upload_kb` = 15 360 KiB) is inclusive:
     * a real JPEG padded to exactly that size is stored, one byte more is refused by the
     * request rule — with no row, file or history left behind.
     */
    public function test_a_photo_of_exactly_the_application_limit_is_accepted_and_one_byte_more_is_not(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());
        $limit = (int) config('inventory.photos.max_upload_kb') * 1024;
        $jpeg = $this->jpegBytes(400, 300);
        $padded = fn (int $size): string => $jpeg.str_repeat("\0", $size - strlen($jpeg)); // trailing bytes after EOI are ignored by decoders

        $this->upload($asset, $this->uploadedImage($padded($limit + 1)))->assertUnprocessable()
            ->assertJsonPath('errors.photo.0', 'Ukuran file foto maksimal 15 MB.');
        $this->assertSame(0, AssetPhoto::query()->count());
        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, MutationLog::query()->where('event_type', 'PHOTO_ADDED')->count());

        $this->upload($asset, $this->uploadedImage($padded($limit)))->assertCreated();
        $this->assertSame(1, AssetPhoto::query()->count());
        $this->assertCount(1, $this->storedFiles());
    }

    /**
     * A file PHP itself refused (above `upload_max_filesize`, or a partial upload)
     * reaches Laravel as an UploadedFile with an error code: the request answers 422
     * with the "failed upload" message, never "required" or a 500, and nothing is
     * stored — the existing photo is untouched.
     */
    #[DataProvider('phpUploadErrors')]
    public function test_an_upload_php_refused_is_a_clear_422_and_stores_nothing(int $error): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());
        $this->upload($asset)->assertCreated();
        $existing = AssetPhoto::query()->sole();

        $refused = new UploadedFile($this->tempImagePath($this->jpegBytes(), '.jpg'), 'foto.jpg', 'image/jpeg', $error, true);
        $this->upload($asset, $refused)->assertUnprocessable()
            ->assertJsonPath('errors.photo.0', 'Foto gagal diunggah. Ukuran file mungkin melebihi batas server — coba foto yang lebih kecil.');

        $this->assertSame([$existing->id], AssetPhoto::query()->pluck('id')->all());
        $this->assertSame([$existing->path], $this->storedFiles());
        $this->assertSame(1, MutationLog::query()->where('event_type', 'PHOTO_ADDED')->count());
    }

    public static function phpUploadErrors(): array
    {
        return ['upload_max_filesize' => [UPLOAD_ERR_INI_SIZE], 'partial upload' => [UPLOAD_ERR_PARTIAL]];
    }

    /**
     * Above `post_max_size` PHP discards the whole body; Laravel's own ValidatePostSize
     * middleware (global, before routing/auth) answers that with a JSON 413. This tests
     * that real Laravel layer with the request PHP would hand over (Content-Length larger
     * than post_max_size, no body) — NOT a web-server/proxy 413, which never reaches PHP.
     */
    public function test_a_body_over_post_max_size_is_a_json_413_and_stores_nothing(): void
    {
        $postMax = $this->iniBytes((string) ini_get('post_max_size'));
        if ($postMax <= 0) {
            $this->markTestSkipped('post_max_size is unlimited in this PHP — Laravel never answers 413 here.');
        }
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());

        $this->call('POST', "/api/assets/{$asset->id}/photos", [], [], [], [
            'CONTENT_LENGTH' => (string) ($postMax + 1),
            'CONTENT_TYPE' => 'multipart/form-data; boundary=----r6',
            'HTTP_ACCEPT' => 'application/json',
        ])->assertStatus(413)->assertJsonPath('message', 'The POST data is too large.');

        $this->assertSame(0, AssetPhoto::query()->count());
        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, MutationLog::query()->where('event_type', 'PHOTO_ADDED')->count());
    }

    /** `post_max_size`-style value to bytes, the same way Laravel's ValidatePostSize reads it. */
    private function iniBytes(string $value): int
    {
        $number = (int) $value;

        return match (strtoupper(substr(trim($value), -1))) {
            'K' => $number * 1024,
            'M' => $number * 1024 ** 2,
            'G' => $number * 1024 ** 3,
            default => $number,
        };
    }

    public function test_uploads_are_rate_limited_to_30_per_minute_per_user(): void
    {
        $asset = $this->asset();
        $user = $this->operator();
        Sanctum::actingAs($user);

        for ($i = 1; $i <= 30; $i++) {
            $this->upload($asset, $this->uploadedImage('x', 'x.jpg'))->assertUnprocessable();
        }
        $this->upload($asset, $this->uploadedImage('x', 'x.jpg'))->assertStatus(429);

        // per user: another account is not affected; reading is not throttled
        Sanctum::actingAs($this->operator());
        $this->upload($asset)->assertCreated();
        Sanctum::actingAs($user);
        $this->getJson("/api/assets/{$asset->id}/photos")->assertOk();
    }

    /* ================================================================== image response */

    public function test_image_is_served_with_private_cache_headers_and_an_etag(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());
        $this->upload($asset)->assertCreated();
        $photo = AssetPhoto::query()->sole();

        $response = $this->get("/api/assets/{$asset->id}/photos/{$photo->id}")->assertOk();

        $this->assertSame(Storage::disk('local')->get($photo->path), $response->getContent());
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('no-cache', $cache);
        $this->assertStringNotContainsString('public', $cache);
        $etag = (string) $response->headers->get('ETag');
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{64}"$/', $etag);
        $this->assertStringNotContainsString('asset-photos', implode(' ', array_map(fn ($v) => implode(',', $v), $response->headers->all())));

        $notModified = $this->get("/api/assets/{$asset->id}/photos/{$photo->id}", ['If-None-Match' => $etag])->assertStatus(304);
        $this->assertSame('', $notModified->getContent());

        $this->get("/api/assets/{$asset->id}/photos/{$photo->id}", ['If-None-Match' => '"something-else"'])->assertOk();
    }

    public function test_missing_file_is_a_404_not_a_500(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());
        $this->upload($asset)->assertCreated();
        $photo = AssetPhoto::query()->sole();
        Storage::disk('local')->delete($photo->path);

        $this->get("/api/assets/{$asset->id}/photos/{$photo->id}", ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_photos_are_not_reachable_through_any_public_url(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());
        $this->upload($asset)->assertCreated();
        $photo = AssetPhoto::query()->sole();

        // Neither `/storage/…` (the private disk's signed-only `serve` route; the SPA
        // catch-all answers first anyway) nor any other path returns the file.
        $bytes = Storage::disk('local')->get($photo->path);
        foreach (['/storage/'.$photo->path, '/'.$photo->path, '/storage/app/private/'.$photo->path] as $url) {
            $response = $this->get($url);
            $this->assertNotSame('image/jpeg', $response->headers->get('Content-Type'), $url);
            $this->assertStringNotContainsString(substr($bytes, 0, 64), (string) $response->getContent(), $url);
        }
        $this->assertFileDoesNotExist(public_path('storage/'.$photo->path));
        $this->assertFileDoesNotExist(public_path($photo->path));
    }

    /* ================================================================== delete */

    public function test_delete_removes_exactly_that_photo_row_and_file(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());
        foreach ([1, 2, 3] as $_) {
            $this->upload($asset)->assertCreated();
        }
        [$one, $two, $three] = AssetPhoto::query()->orderBy('slot')->get()->all();

        $this->deleteJson("/api/assets/{$asset->id}/photos/{$two->id}")->assertNoContent();

        $this->assertSame([$one->id, $three->id], AssetPhoto::query()->orderBy('slot')->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$one->path, $three->path], $this->storedFiles());
        $this->deleteJson("/api/assets/{$asset->id}/photos/{$two->id}")->assertNotFound();
        $this->get("/api/assets/{$asset->id}/photos/{$two->id}", ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_file_delete_failure_after_commit_is_logged_and_left_for_prune(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());
        $this->upload($asset)->assertCreated();
        $photo = AssetPhoto::query()->sole();
        $this->diskWhoseDeleteFails();
        Log::spy();

        $this->deleteJson("/api/assets/{$asset->id}/photos/{$photo->id}")->assertNoContent();

        $this->assertSame(0, AssetPhoto::query()->count(), 'the row is gone — the photo is no longer reachable');
        $this->assertSame([$photo->path], $this->storedFiles());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => $message === 'asset_photo.file_delete_failed')->once();

        // the prune command picks the orphan up once it is old enough — this was the
        // asset's only photo, so the table is empty and --delete needs --force (R4)
        Storage::set('local', Storage::createLocalDriver(['root' => Storage::disk('local')->path('')]));
        touch(Storage::disk('local')->path($photo->path), time() - 7200);
        $this->artisan('assets:photos:prune-orphans', ['--delete' => true])
            ->expectsOutputToContain('asset_photos table is EMPTY')
            ->assertExitCode(2);
        $this->assertSame([$photo->path], $this->storedFiles());
        $this->artisan('assets:photos:prune-orphans', ['--delete' => true, '--force' => true])
            ->expectsOutputToContain("deleted: {$photo->path}")
            ->assertSuccessful();
        $this->assertSame([], $this->storedFiles());
    }

    /* ================================================================== history */

    public function test_history_records_photo_events_with_a_safe_summary_only(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());
        $this->upload($asset, $this->uploadedImage($this->jpegBytes(400, 300)))->assertCreated();
        $photo = AssetPhoto::query()->sole();
        $kb = max(1, (int) round($photo->size_bytes / 1024));
        $this->deleteJson("/api/assets/{$asset->id}/photos/{$photo->id}")->assertNoContent();

        $events = collect($this->getJson("/api/assets/{$asset->id}/mutations?sort=id&direction=asc")->assertOk()->json('data'));
        $added = $events->firstWhere('event_type', 'PHOTO_ADDED');
        $deleted = $events->firstWhere('event_type', 'PHOTO_DELETED');

        $this->assertSame("Foto slot 1 ditambahkan (400×300, {$kb} KB)", $added['mutation_note']);
        $this->assertSame("Foto slot 1 dihapus permanen (400×300, {$kb} KB)", $deleted['mutation_note']);
        foreach ([$added, $deleted] as $event) {
            $this->assertSame($event['before_snapshot'], $event['after_snapshot'], 'a photo event changes no asset field');
            $this->assertSame($asset->id, $event['after_snapshot']['id']);
            $this->assertArrayNotHasKey('photos', $event['after_snapshot']);
            $this->assertFalse($event['is_revertable']);
            $this->assertFalse($event['can_revert']);
            $this->assertNull($event['mutation_type']);
        }

        $raw = json_encode(MutationLog::query()->whereIn('event_type', ['PHOTO_ADDED', 'PHOTO_DELETED'])->get()->toArray());
        $this->assertStringNotContainsString('asset-photos', $raw);
        $this->assertStringNotContainsString('.jpg', $raw);
        $this->assertStringNotContainsString('base64', $raw);
        $this->assertStringNotContainsString(base64_encode(substr($this->jpegBytes(400, 300), 0, 30)), $raw);

        $this->getJson("/api/assets/{$asset->id}/mutations?event_type=PHOTO_ADDED")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_photo_events_cannot_be_reverted(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());
        $this->upload($asset)->assertCreated();
        $log = MutationLog::query()->where('event_type', MutationEventType::PhotoAdded)->sole();

        $this->postJson("/api/mutations/{$log->id}/revert")->assertStatus(409);

        $this->assertSame(1, AssetPhoto::query()->count());
        $this->assertSame(0, MutationLog::query()->whereNotNull('reverted_mutation_id')->count());
    }

    public function test_photo_upload_does_not_modify_the_asset_row(): void
    {
        $asset = $this->asset('02', ['asset_source' => 'bosda', 'updated_at' => '2025-01-01 00:00:00']);
        $before = DB::table('assets')->where('id', $asset->id)->first();
        Sanctum::actingAs($this->operator());

        $this->upload($asset)->assertCreated();
        $photo = AssetPhoto::query()->sole();
        $this->deleteJson("/api/assets/{$asset->id}/photos/{$photo->id}")->assertNoContent();

        $this->assertEquals($before, DB::table('assets')->where('id', $asset->id)->first());
    }

    public function test_reverting_asset_events_never_touches_photos(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());
        $this->upload($asset)->assertCreated();
        $photo = AssetPhoto::query()->sole();

        $this->putJson("/api/assets/{$asset->id}", [
            'location_code' => '02', 'category_code' => '02', 'subcategory_code' => '001',
            'brand_model' => 'Merek Baru', 'condition' => 'baik',
        ])->assertOk();
        $edit = MutationLog::query()->where('event_type', 'EDIT')->sole();
        $this->postJson("/api/mutations/{$edit->id}/revert")->assertOk();

        $this->deleteJson("/api/assets/{$asset->id}")->assertNoContent();
        $softDelete = MutationLog::query()->where('event_type', 'SOFT_DELETE')->sole();
        $this->postJson("/api/mutations/{$softDelete->id}/revert")->assertOk();

        $this->postJson("/api/assets/{$asset->id}/write-off", ['written_off_on' => now()->toDateString()])->assertOk();
        $writeOff = MutationLog::query()->where('event_type', 'WRITE_OFF')->sole();
        $this->postJson("/api/mutations/{$writeOff->id}/revert")->assertOk();

        $this->assertSame([$photo->id], AssetPhoto::query()->pluck('id')->all());
        $this->assertSame([$photo->path], $this->storedFiles());
        $this->get("/api/assets/{$asset->id}/photos/{$photo->id}")->assertOk();
    }

    /* ================================================================== asset lifecycle */

    public function test_photos_survive_soft_delete_and_restore_and_trashed_assets_reject_writes_with_404(): void
    {
        $asset = $this->asset();
        $operator = $this->operator();
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        Sanctum::actingAs($operator);
        $this->upload($asset)->assertCreated();
        $photo = AssetPhoto::query()->sole();

        $this->deleteJson("/api/assets/{$asset->id}")->assertNoContent();
        $this->assertSame(1, AssetPhoto::query()->count());
        $this->assertSame([$photo->path], $this->storedFiles());

        // writes on a trashed asset: plain 404, nothing changes
        $this->upload($asset)->assertNotFound();
        $this->deleteJson("/api/assets/{$asset->id}/photos/{$photo->id}")->assertNotFound();
        $this->assertSame(1, AssetPhoto::query()->count());

        // reads follow the detail rule: restore-holder yes, viewer no
        $this->getJson("/api/assets/{$asset->id}/photos")->assertOk()->assertJsonCount(1, 'data');
        $this->get("/api/assets/{$asset->id}/photos/{$photo->id}")->assertOk();
        Sanctum::actingAs($viewer);
        $this->getJson("/api/assets/{$asset->id}/photos")->assertNotFound();
        $this->get("/api/assets/{$asset->id}/photos/{$photo->id}", ['Accept' => 'application/json'])->assertNotFound();

        Sanctum::actingAs($operator);
        $this->postJson("/api/assets/{$asset->id}/restore")->assertOk();

        Sanctum::actingAs($viewer);
        $this->getJson("/api/assets/{$asset->id}/photos")->assertOk()->assertJsonPath('data.0.id', $photo->id);
        $this->get("/api/assets/{$asset->id}/photos/{$photo->id}")->assertOk();
    }

    public function test_photos_survive_write_off_and_can_still_be_managed(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());
        $this->upload($asset)->assertCreated();

        $this->postJson("/api/assets/{$asset->id}/write-off", ['written_off_on' => now()->toDateString()])->assertOk();

        $this->getJson("/api/assets/{$asset->id}/photos")->assertOk()->assertJsonCount(1, 'data');
        $this->upload($asset)->assertCreated()->assertJsonPath('data.slot', 2);
    }

    /* ================================================================== other features unchanged */

    public function test_inventory_list_detail_and_trash_do_not_expose_photos(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());
        $this->upload($asset)->assertCreated();

        $list = $this->getJson('/api/assets')->assertOk();
        $this->assertArrayNotHasKey('photos', $list->json('data.0'));
        $this->assertStringNotContainsString('/photos', $list->getContent());
        $this->getJson("/api/assets/{$asset->id}")->assertOk()->assertJsonMissingPath('data.photos');
    }

    public function test_excel_export_contains_no_photo_data(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());
        $this->upload($asset)->assertCreated();

        $content = $this->get('/api/assets/export')->assertOk()->getContent();
        $path = $this->tempImagePath($content, '.xlsx');
        $spreadsheet = IOFactory::load($path);

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $this->assertCount(0, $sheet->getDrawingCollection(), 'no embedded images');
            foreach ($sheet->toArray() as $row) {
                foreach ($row as $cell) {
                    $text = strtolower((string) $cell);
                    $this->assertStringNotContainsString('asset-photos', $text);
                    $this->assertStringNotContainsString('/photos', $text);
                    $this->assertStringNotContainsString('foto', $text);
                }
            }
        }
        $spreadsheet->disconnectWorksheets();
    }

    public function test_label_pdf_is_still_generated_for_an_asset_with_photos(): void
    {
        $asset = $this->asset();
        Sanctum::actingAs($this->operator());
        $this->upload($asset)->assertCreated();

        $response = $this->get("/api/assets/{$asset->id}/label")->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_reimporting_an_asset_with_photos_does_not_change_them(): void
    {
        $this->room('01');
        $asset = Asset::factory()->identity('01', '02', '001', '560', 2020)->create();
        Sanctum::actingAs($this->operator());
        $this->upload($asset)->assertCreated();
        $photo = AssetPhoto::query()->sole()->toArray();

        $upload = $this->makeImportUpload('02', [
            $this->validImportRowCells(['E' => '560']),
            $this->validImportRowCells(['E' => '561']),
        ]);
        $batchId = app(ImportManager::class)->stageFile($upload->getPathname())['batch_id'];
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk();

        $this->assertSame(1, Asset::query()->where('sequence_no', '560')->count());
        $this->assertSame([$photo], AssetPhoto::query()->get()->map->toArray()->all());
        $this->assertCount(1, $this->storedFiles());
        $newAsset = Asset::query()->where('sequence_no', '561')->sole();
        $this->getJson("/api/assets/{$newAsset->id}/photos")->assertOk()->assertJsonPath('data', []);
    }

    /* ================================================================== fixtures */

    private function room(string $location): void
    {
        Room::factory()->create(['location_code' => $location, 'name' => 'KEUANGAN']);
    }

    /** Swaps the fake disk for one whose delete() fails (the file stays). */
    private function diskWhoseDeleteFails(): void
    {
        $this->swapDisk(failDelete: true);
    }

    /** Same fake disk (same root), but put() and/or delete() report failure. */
    private function swapDisk(bool $failPut = false, bool $failDelete = false): void
    {
        $real = Storage::disk('local');
        Storage::set('local', new class($real->getDriver(), $real->getAdapter(), $real->getConfig(), $failPut, $failDelete) extends FilesystemAdapter
        {
            public function __construct($driver, $adapter, array $config, private bool $failPut, private bool $failDelete)
            {
                parent::__construct($driver, $adapter, $config);
            }

            public function put($path, $contents, $options = [])
            {
                return $this->failPut ? false : parent::put($path, $contents, $options);
            }

            public function delete($paths)
            {
                return $this->failDelete ? false : parent::delete($paths);
            }
        });
    }
}
