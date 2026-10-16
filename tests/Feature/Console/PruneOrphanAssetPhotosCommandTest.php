<?php

namespace Tests\Feature\Console;

use App\Models\Asset;
use App\Models\AssetPhoto;
use App\Models\Category;
use App\Models\Location;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Foto Aset — `php artisan assets:photos:prune-orphans`. Runs against
 * `Storage::fake('local')` only (never the real storage/app/private).
 */
class PruneOrphanAssetPhotosCommandTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        Location::query()->firstOrCreate(['code' => '02'], ['name' => 'Lokasi 02', 'is_active' => true]);
        Category::query()->firstOrCreate(['code' => '02'], ['name' => 'Meubelair', 'is_active' => true]);
        $meja = Subcategory::query()->firstOrCreate(['category_code' => '02', 'code' => '001'], ['name' => 'Meja', 'is_active' => true]);
        $this->asset = Asset::factory()->forSubcategory($meja)->create(['location_code' => '02']);
    }

    /** Writes a file on the fake disk and back-dates it by `$minutesOld`. */
    private function file(string $relative, int $minutesOld): string
    {
        $disk = Storage::disk('local');
        $disk->put($relative, 'jpeg-bytes');
        touch($disk->path($relative), time() - $minutesOld * 60);

        return $relative;
    }

    private function photoFile(int $minutesOld, bool $referenced = false, int $slot = 1): string
    {
        $path = $this->file("asset-photos/{$this->asset->id}/".Str::uuid().'.jpg', $minutesOld);
        if ($referenced) {
            AssetPhoto::query()->create([
                'asset_id' => $this->asset->id, 'slot' => $slot, 'path' => $path, 'mime' => 'image/jpeg',
                'size_bytes' => 10, 'width' => 1, 'height' => 1,
            ]);
        }

        return $path;
    }

    private function prune(array $options = []): array
    {
        $exit = Artisan::call('assets:photos:prune-orphans', $options);

        return [$exit, Artisan::output()];
    }

    public function test_default_is_a_dry_run_that_only_lists_old_unreferenced_files(): void
    {
        $orphan = $this->photoFile(120);
        $referenced = $this->photoFile(120, referenced: true);
        $young = $this->photoFile(5);

        [$exit, $output] = $this->prune();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('DRY-RUN', $output);
        $this->assertStringContainsString("would delete: {$orphan}", $output);
        $this->assertStringNotContainsString($referenced, $output);
        $this->assertStringNotContainsString($young, $output);
        $this->assertStringContainsString('1 orphan(s) would be deleted, 1 too recent to judge', $output);
        foreach ([$orphan, $referenced, $young] as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }

    public function test_delete_mode_removes_only_old_unreferenced_application_files(): void
    {
        $orphan = $this->photoFile(120);
        $referenced = $this->photoFile(120, referenced: true);
        $young = $this->photoFile(5);
        $foreignName = $this->file("asset-photos/{$this->asset->id}/catatan.txt", 500);
        $outsideRoot = $this->file('imports/old-upload.xlsx', 5000);

        [$exit, $output] = $this->prune(['--delete' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString("deleted: {$orphan}", $output);
        $this->assertStringContainsString('(not an application photo file name)', $output);
        Storage::disk('local')->assertMissing($orphan);
        foreach ([$referenced, $young, $foreignName, $outsideRoot] as $path) {
            Storage::disk('local')->assertExists($path);
        }
        $this->assertSame(1, AssetPhoto::query()->count(), 'the database is never changed');
    }

    public function test_older_than_accepts_units_and_moves_the_cutoff(): void
    {
        $twoHours = $this->photoFile(120);
        $fiveHours = $this->photoFile(300);

        [, $output] = $this->prune(['--older-than' => '3h', '--delete' => true]);

        $this->assertStringContainsString("deleted: {$fiveHours}", $output);
        Storage::disk('local')->assertExists($twoHours);
        Storage::disk('local')->assertMissing($fiveHours);

        [, $output] = $this->prune(['--older-than' => '1d']);
        $this->assertStringContainsString('0 orphan(s)', $output);
        [, $output] = $this->prune(['--older-than' => '90']);
        $this->assertStringContainsString("would delete: {$twoHours}", $output);
    }

    public function test_invalid_or_too_small_age_is_refused(): void
    {
        $orphan = $this->photoFile(120);

        $this->assertSame(2, $this->prune(['--older-than' => 'kemarin', '--delete' => true])[0]);
        [$exit, $output] = $this->prune(['--older-than' => '5', '--delete' => true]);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('at least 10 minutes', $output);

        Storage::disk('local')->assertExists($orphan);
    }

    public function test_rows_without_a_file_are_reported_and_left_alone(): void
    {
        AssetPhoto::query()->create([
            'asset_id' => $this->asset->id, 'slot' => 2, 'path' => "asset-photos/{$this->asset->id}/".Str::uuid().'.jpg',
            'mime' => 'image/jpeg', 'size_bytes' => 10, 'width' => 1, 'height' => 1,
        ]);

        [$exit, $output] = $this->prune(['--delete' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('slot 2) has no file', $output);
        $this->assertSame(1, AssetPhoto::query()->count());
    }

    public function test_missing_photo_root_is_not_an_error(): void
    {
        [$exit, $output] = $this->prune();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('nothing to check', $output);
    }

    public function test_symlinks_are_never_followed_or_deleted(): void
    {
        $outside = $this->file('secret/keep.jpg', 500);
        $disk = Storage::disk('local');
        $disk->makeDirectory("asset-photos/{$this->asset->id}");
        $link = $disk->path("asset-photos/{$this->asset->id}/".Str::uuid().'.jpg');

        if (! @symlink($disk->path($outside), $link)) {
            $this->markTestSkipped('This environment cannot create symlinks (Windows without privilege).');
        }

        [, $output] = $this->prune(['--delete' => true]);

        $this->assertStringContainsString('symlink — never followed or deleted', $output);
        $this->assertTrue(is_link($link));
        $disk->assertExists($outside);
        @unlink($link);
    }
}
