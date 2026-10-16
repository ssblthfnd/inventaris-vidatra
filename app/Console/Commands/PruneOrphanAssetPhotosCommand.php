<?php

namespace App\Console\Commands;

use App\Models\AssetPhoto;
use App\Services\Asset\AssetPhotoService;
use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use UnexpectedValueException;

/**
 * Foto Aset — finds (and, only with `--delete`, removes) photo files on the
 * private disk that no `asset_photos` row references any more: left behind
 * when a file could not be deleted after its row was, or when cleanup after a
 * failed upload itself failed (both are logged as `asset_photo.*`).
 *
 *   php artisan assets:photos:prune-orphans                     # dry-run (default), files older than 60 min
 *   php artisan assets:photos:prune-orphans --older-than=1d     # dry-run, older than one day
 *   php artisan assets:photos:prune-orphans --older-than=2h --delete
 *
 * Manual only — never scheduled, no queue. Safety rules:
 *  - only `{directory}/{asset_id}/{uuid}.jpg` files under the configured photo
 *    root are candidates; any other file is listed as ignored, never deleted;
 *  - symlinks are never followed nor deleted, and every candidate's real path
 *    must lie inside the real photo root (no traversal out of it);
 *  - a file younger than `--older-than` is never a candidate: an upload writes
 *    its file BEFORE its row is committed, so a fresh unreferenced file may be
 *    an upload still in flight. The minimum accepted age is 10 minutes;
 *  - the database reference is checked again immediately before each delete;
 *  - rows whose file is missing are reported, and the database is never changed.
 */
class PruneOrphanAssetPhotosCommand extends Command
{
    /** Never treat a file younger than this as an orphan, whatever is passed. */
    private const MIN_AGE_MINUTES = 10;

    private const PHOTO_FILE = '#^(\d+)/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.jpg$#';

    protected $signature = 'assets:photos:prune-orphans
        {--older-than= : Minimum file age, e.g. 90 (minutes), 90m, 12h, 7d. Default: inventory.photos.prune_min_age_minutes}
        {--delete : Actually delete the orphan files (without it, nothing is changed)}';

    protected $description = 'List (dry-run, default) or delete asset photo files that no asset_photos row references';

    public function handle(AssetPhotoService $photos): int
    {
        $minutes = $this->parseAge($this->option('older-than'));
        if ($minutes === null) {
            $this->error('Invalid --older-than. Use minutes (e.g. 90) or a value with m/h/d (e.g. 90m, 12h, 7d).');

            return self::INVALID;
        }
        if ($minutes < self::MIN_AGE_MINUTES) {
            $this->error('--older-than must be at least '.self::MIN_AGE_MINUTES.' minutes (protects uploads still in progress).');

            return self::INVALID;
        }

        $delete = (bool) $this->option('delete');
        $directory = AssetPhotoService::directory();
        $disk = $photos->disk();
        $cutoff = time() - $minutes * 60;

        $this->line(($delete ? '<comment>DELETE MODE</comment>' : '<info>DRY-RUN</info> (nothing is changed; add --delete to remove)').
            " — photo root: {$directory}/, files older than {$minutes} minute(s).");

        $root = realpath($disk->path($directory));
        if ($root === false || ! is_dir($root)) {
            $this->info('Photo root does not exist yet — nothing to check.');
            $this->reportMissingFiles($disk);

            return self::SUCCESS;
        }

        [$candidates, $ignored, $young] = $this->scan($root, $cutoff);

        foreach ($ignored as $reason) {
            $this->line("  ignored: {$reason}");
        }

        $referenced = $this->referencedPaths(array_map(fn (array $c) => $directory.'/'.$c['relative'], $candidates));
        $orphans = array_values(array_filter($candidates, fn (array $c) => ! isset($referenced[$directory.'/'.$c['relative']])));

        $deleted = 0;
        $failed = 0;
        foreach ($orphans as $orphan) {
            $relative = $directory.'/'.$orphan['relative'];
            $label = sprintf('%s (%s KB, %s)', $relative, number_format($orphan['size'] / 1024, 0), date('Y-m-d H:i', $orphan['mtime']));

            if (! $delete) {
                $this->line("  would delete: {$label}");

                continue;
            }

            // re-check right before deleting — a row may have appeared meanwhile
            if (AssetPhoto::query()->where('path', $relative)->exists()) {
                $this->line("  kept (now referenced): {$label}");

                continue;
            }

            if ($this->unlinkInside($orphan['real'], $root)) {
                $deleted++;
                $this->line("  deleted: {$label}");
            } else {
                $failed++;
                $this->error("  FAILED to delete: {$label}");
            }
        }

        $this->reportMissingFiles($disk);

        $this->newLine();
        $this->line(sprintf(
            'Checked %d photo file(s): %d referenced, %d orphan(s) %s, %d too recent to judge, %d ignored.',
            count($candidates) + $young,
            count($candidates) - count($orphans),
            count($orphans),
            $delete ? "({$deleted} deleted, {$failed} failed)" : 'would be deleted',
            $young,
            count($ignored),
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{0: list<array{relative: string, real: string, size: int, mtime: int}>, 1: list<string>, 2: int}
     */
    private function scan(string $root, int $cutoff): array
    {
        $candidates = [];
        $ignored = [];
        $young = 0;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), // symlinked dirs are not followed
                RecursiveIteratorIterator::SELF_FIRST,
            );

            /** @var SplFileInfo $entry */
            foreach ($iterator as $entry) {
                $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));

                if ($entry->isLink()) {
                    $ignored[] = "{$relative} (symlink — never followed or deleted)";

                    continue;
                }
                if ($entry->isDir()) {
                    if (preg_match('#^\d+$#', $relative) !== 1) {
                        $ignored[] = "{$relative}/ (unexpected directory)";
                    }

                    continue;
                }
                if (preg_match(self::PHOTO_FILE, $relative) !== 1) {
                    $ignored[] = "{$relative} (not an application photo file name)";

                    continue;
                }

                $real = realpath($entry->getPathname());
                if ($real === false || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
                    $ignored[] = "{$relative} (resolves outside the photo root)";

                    continue;
                }

                $mtime = (int) $entry->getMTime();
                if ($mtime > $cutoff) {
                    $young++;

                    continue;
                }

                $candidates[] = ['relative' => $relative, 'real' => $real, 'size' => (int) $entry->getSize(), 'mtime' => $mtime];
            }
        } catch (UnexpectedValueException $e) {
            $ignored[] = 'part of the photo root could not be read: '.$e->getMessage();
        }

        return [$candidates, $ignored, $young];
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, true>
     */
    private function referencedPaths(array $paths): array
    {
        $referenced = [];
        foreach (array_chunk($paths, 500) as $chunk) {
            foreach (AssetPhoto::query()->whereIn('path', $chunk)->pluck('path') as $path) {
                $referenced[$path] = true;
            }
        }

        return $referenced;
    }

    private function unlinkInside(string $real, string $root): bool
    {
        clearstatcache(true, $real);
        if (is_link($real) || ! is_file($real) || ! str_starts_with((string) realpath($real), $root.DIRECTORY_SEPARATOR)) {
            return false;
        }

        try {
            return @unlink($real);
        } catch (Throwable) {
            return false;
        }
    }

    private function reportMissingFiles(FilesystemAdapter $disk): void
    {
        AssetPhoto::query()->select(['id', 'asset_id', 'slot', 'path'])->chunkById(500, function ($rows) use ($disk): void {
            foreach ($rows as $row) {
                if (! $disk->exists($row->path)) {
                    $this->warn("  row #{$row->id} (asset {$row->asset_id}, slot {$row->slot}) has no file — database left unchanged.");
                }
            }
        });
    }

    private function parseAge(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return (int) config('inventory.photos.prune_min_age_minutes', 60);
        }

        if (! is_string($value) || preg_match('/^(\d+)\s*([mhd]?)$/i', trim($value), $m) !== 1) {
            return null;
        }

        return (int) $m[1] * match (strtolower($m[2])) {
            'h' => 60,
            'd' => 1440,
            default => 1,
        };
    }
}
