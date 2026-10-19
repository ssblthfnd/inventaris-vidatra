<?php

namespace Tests\Feature\Console;

use App\Models\Asset;
use App\Models\AssetPhoto;
use App\Models\Category;
use App\Models\Location;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        $this->photoFile(600, referenced: true); // a non-empty table, so --delete is not refused
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

    /* ================================================================== R4: target, guards, logging */

    public function test_header_names_environment_database_and_disk_without_absolute_paths(): void
    {
        [, $output] = $this->prune();

        $this->assertStringContainsString('Target — environment: testing, database: inventaris_vidatra_test (connection mysql), disk: local', $output);
        $this->assertStringNotContainsString(base_path(), $output);
        $this->assertStringNotContainsString(rtrim(Storage::disk('local')->path(''), '\\/'), $output);
    }

    public function test_delete_is_refused_when_orphans_exist_but_the_photo_table_is_empty(): void
    {
        Log::spy();
        $orphan = $this->photoFile(120);

        [$exit, $output] = $this->prune(['--delete' => true]);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('REFUSED — nothing was deleted', $output);
        $this->assertStringContainsString('asset_photos table is EMPTY although 1 photo file(s)', $output);
        $this->assertStringNotContainsString("deleted: {$orphan}", $output);
        Storage::disk('local')->assertExists($orphan);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'asset_photo.prune_refused' && $context['orphans'] === 1)->once();
    }

    public function test_dry_run_with_an_empty_table_only_warns_and_deletes_nothing(): void
    {
        $orphan = $this->photoFile(120);

        [$exit, $output] = $this->prune();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString("would delete: {$orphan}", $output);
        $this->assertStringContainsString('note: --delete would be refused: the asset_photos table is EMPTY', $output);
        Storage::disk('local')->assertExists($orphan);
    }

    public function test_force_lifts_only_the_empty_table_guard_never_the_path_checks(): void
    {
        $orphan = $this->photoFile(120);
        $young = $this->photoFile(5);
        $foreignName = $this->file("asset-photos/{$this->asset->id}/catatan.txt", 500);
        $unexpected = $this->file('asset-photos/lain/'.Str::uuid().'.jpg', 500);
        $outsideRoot = $this->file('imports/old-upload.xlsx', 5000);

        // argument validation still applies with --force
        $this->assertSame(2, $this->prune(['--delete' => true, '--force' => true, '--older-than' => '5'])[0]);
        $this->assertSame(2, $this->prune(['--delete' => true, '--force' => true, '--max-delete' => 'semua'])[0]);
        Storage::disk('local')->assertExists($orphan);

        [$exit, $output] = $this->prune(['--delete' => true, '--force' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString("deleted: {$orphan}", $output);
        Storage::disk('local')->assertMissing($orphan);
        foreach ([$young, $foreignName, $unexpected, $outsideRoot] as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }

    public function test_max_delete_caps_the_delete_run_and_force_does_not_lift_it(): void
    {
        Log::spy();
        $this->photoFile(120, referenced: true);
        $first = $this->photoFile(120);
        $second = $this->photoFile(130);

        foreach ([['--max-delete' => '1'], ['--max-delete' => '1', '--force' => true]] as $options) {
            [$exit, $output] = $this->prune(['--delete' => true] + $options);
            $this->assertSame(2, $exit);
            $this->assertStringContainsString('2 orphan file(s) found, more than --max-delete=1', $output);
        }
        Storage::disk('local')->assertExists($first);
        Storage::disk('local')->assertExists($second);

        foreach (['0', '-1', '1.5', 'semua'] as $invalid) {
            $this->assertSame(2, $this->prune(['--delete' => true, '--max-delete' => $invalid])[0], $invalid);
        }

        [$exit, $output] = $this->prune(['--delete' => true, '--max-delete' => '2']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('2 orphan(s) (2 deleted, 0 failed)', $output);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertMissing($second);
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === 'asset_photo.orphan_deleted'
            && $context === ['asset_id' => $this->asset->id, 'path' => $first])->once();
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === 'asset_photo.prune_finished'
            && $context['deleted'] === 2 && $context['failed'] === 0)->once();
    }

    public function test_a_file_that_becomes_referenced_while_the_command_runs_is_kept(): void
    {
        $this->photoFile(120, referenced: true);
        $orphan = $this->photoFile(120);

        // an upload commits a row for this path between the scan and the delete
        $inserted = false;
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$inserted, $orphan): void {
            if ($inserted || ! str_contains($query, 'select exists') || ($bindings[0] ?? null) !== $orphan) {
                return;
            }
            $inserted = true;
            AssetPhoto::query()->create([
                'asset_id' => $this->asset->id, 'slot' => 2, 'path' => $orphan, 'mime' => 'image/jpeg',
                'size_bytes' => 10, 'width' => 1, 'height' => 1,
            ]);
        });

        [$exit, $output] = $this->prune(['--delete' => true]);

        $this->assertTrue($inserted, 'the re-check query ran');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString("kept (now referenced): {$orphan}", $output);
        Storage::disk('local')->assertExists($orphan);
    }

    public function test_a_failed_unlink_is_reported_logged_and_exits_1(): void
    {
        $this->photoFile(120, referenced: true);
        $orphan = $this->photoFile(120);
        $file = Storage::disk('local')->path($orphan);

        // a real unlink failure: a read-only file on Windows, a read-only directory elsewhere
        $locked = PHP_OS_FAMILY === 'Windows' ? $file : dirname($file);
        if ($locked !== $file && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can unlink inside a read-only directory.');
        }
        Log::spy();
        chmod($locked, $locked === $file ? 0444 : 0555);

        try {
            [$exit, $output] = $this->prune(['--delete' => true]);
        } finally {
            chmod($locked, $locked === $file ? 0644 : 0755);
        }

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("FAILED to delete: {$orphan}", $output);
        $this->assertStringContainsString('(0 deleted, 1 failed)', $output);
        Storage::disk('local')->assertExists($orphan);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'asset_photo.orphan_delete_failed'
            && $context === ['asset_id' => $this->asset->id, 'path' => $orphan])->once();
    }

    public function test_unexpected_directories_are_listed_and_nothing_inside_them_is_deleted(): void
    {
        $this->photoFile(120, referenced: true);
        $uuid = Str::uuid()->toString();
        $named = $this->file("asset-photos/lain/{$uuid}.jpg", 500);
        $nested = $this->file("asset-photos/{$this->asset->id}/sub/{$uuid}.jpg", 500);

        [$exit, $output] = $this->prune(['--delete' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('ignored: lain/ (unexpected directory)', $output);
        $this->assertStringContainsString("ignored: {$this->asset->id}/sub/ (unexpected directory)", $output);
        $this->assertStringContainsString("ignored: lain/{$uuid}.jpg (not an application photo file name)", $output);
        $this->assertStringContainsString("ignored: {$this->asset->id}/sub/{$uuid}.jpg (not an application photo file name)", $output);
        Storage::disk('local')->assertExists($named);
        Storage::disk('local')->assertExists($nested);
    }
}
