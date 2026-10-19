<?php

namespace App\Console\Commands;

use App\Models\AssetPhoto;
use App\Services\Asset\AssetPhotoService;
use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
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
 *   php artisan assets:photos:prune-orphans --older-than=1d --delete --max-delete=3
 *
 * Manual only — never scheduled, no queue. Safety rules:
 *  - the target (environment, database, disk) is printed first, in both modes,
 *    so the operator sees WHICH database the files are compared against;
 *  - only `{directory}/{asset_id}/{uuid}.jpg` files under the configured photo
 *    root are candidates; any other file is listed as ignored, never deleted;
 *  - symlinks are never followed nor deleted, and every candidate's real path
 *    must lie inside the real photo root (no traversal out of it);
 *  - a file younger than `--older-than` is never a candidate: an upload writes
 *    its file BEFORE its row is committed, so a fresh unreferenced file may be
 *    an upload still in flight. The minimum accepted age is 10 minutes;
 *  - `--delete` is REFUSED (exit 2, nothing deleted) when orphans were found
 *    but the `asset_photos` table is empty — the files almost certainly belong
 *    to another database (wrong `--env`/.env, unfinished restore). `--force`
 *    lifts only that guard;
 *  - `--max-delete=N` (optional, operator-chosen — typically the orphan count
 *    the dry-run showed) refuses `--delete` when more orphans are found. There
 *    is no default cap: a fixed number would be too loose to notice an older
 *    database restore (a handful of newer photos) yet could block a legitimate
 *    cleanup, so the operator ties the delete run to the dry-run they reviewed;
 *  - the database reference is checked again immediately before each delete;
 *  - every deletion / failed deletion / refusal is logged (relative path and
 *    asset id only), and a failed deletion or an incomplete scan exits 1;
 *  - rows whose file is missing are reported, and the database is never changed.
 */
class PruneOrphanAssetPhotosCommand extends Command
{
    /** Never treat a file younger than this as an orphan, whatever is passed. */
    private const MIN_AGE_MINUTES = 10;

    private const PHOTO_FILE = '#^(\d+)/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.jpg$#';

    protected $signature = 'assets:photos:prune-orphans
        {--older-than= : Minimum file age, e.g. 90 (minutes), 90m, 12h, 7d. Default: inventory.photos.prune_min_age_minutes}
        {--delete : Actually delete the orphan files (without it, nothing is changed)}
        {--max-delete= : With --delete: refuse if MORE orphan files than this are found (e.g. the count the dry-run showed)}
        {--force : With --delete: proceed although the asset_photos table is empty (lifts only that guard)}';

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

        $maxDelete = $this->parseMaxDelete($this->option('max-delete'));
        if ($maxDelete === false) {
            $this->error('Invalid --max-delete. Use a whole number of at least 1 (e.g. the orphan count of the dry-run).');

            return self::INVALID;
        }

        $delete = (bool) $this->option('delete');
        $force = (bool) $this->option('force');
        $directory = AssetPhotoService::directory();
        $disk = $photos->disk();
        $cutoff = time() - $minutes * 60;

        $this->line(($delete ? '<comment>DELETE MODE</comment>' : '<info>DRY-RUN</info> (nothing is changed; add --delete to remove)').
            " — photo root: {$directory}/, files older than {$minutes} minute(s).");
        $this->line($this->targetLine());

        $root = realpath($disk->path($directory));
        if ($root === false || ! is_dir($root)) {
            $this->info('Photo root does not exist yet — nothing to check.');
            $this->reportMissingFiles($disk);

            return self::SUCCESS;
        }

        [$candidates, $ignored, $young, $scanError] = $this->scan($root, $cutoff, $directory);

        foreach ($ignored as $reason) {
            $this->line("  ignored: {$reason}");
        }
        if ($scanError !== null) {
            $this->error("  scan incomplete — part of the photo root could not be read: {$scanError}");
        }

        $referenced = $this->referencedPaths(array_map(fn (array $c) => $directory.'/'.$c['relative'], $candidates));
        $orphans = array_values(array_filter($candidates, fn (array $c) => ! isset($referenced[$directory.'/'.$c['relative']])));

        $refusals = $this->refusals(count($orphans), $maxDelete, $force);
        if ($refusals !== []) {
            if ($delete) {
                foreach ($refusals as $reason) {
                    $this->error("REFUSED — nothing was deleted: {$reason}");
                }
                Log::warning('asset_photo.prune_refused', ['orphans' => count($orphans), 'reasons' => $refusals]);

                return self::INVALID;
            }
            foreach ($refusals as $reason) {
                $this->warn("  note: --delete would be refused: {$reason}");
            }
        }

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
                Log::info('asset_photo.orphan_deleted', ['asset_id' => $orphan['asset_id'], 'path' => $relative]);
            } else {
                $failed++;
                $this->error("  FAILED to delete: {$label}");
                Log::warning('asset_photo.orphan_delete_failed', ['asset_id' => $orphan['asset_id'], 'path' => $relative]);
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

        if ($delete) {
            Log::info('asset_photo.prune_finished', [
                'orphans' => count($orphans), 'deleted' => $deleted, 'failed' => $failed, 'scan_incomplete' => $scanError !== null,
            ]);
        }

        return $failed > 0 || $scanError !== null ? self::FAILURE : self::SUCCESS;
    }

    /**
     * "Target — environment: production, database: inventaris_vidatra (connection mysql), disk: local".
     * Names only: no host, credentials or absolute paths.
     */
    private function targetLine(): string
    {
        $connection = AssetPhoto::query()->getConnection();

        return sprintf(
            'Target — environment: %s, database: %s (connection %s), disk: %s',
            $this->laravel->environment(),
            basename((string) $connection->getDatabaseName()),
            $connection->getName(),
            (string) config('inventory.photos.disk', 'local'),
        );
    }

    /**
     * Why `--delete` must not run, if it must not. The empty-table check only
     * costs a query when orphans were actually found.
     *
     * @return list<string>
     */
    private function refusals(int $orphans, ?int $maxDelete, bool $force): array
    {
        $reasons = [];

        if ($maxDelete !== null && $orphans > $maxDelete) {
            $reasons[] = "{$orphans} orphan file(s) found, more than --max-delete={$maxDelete}. Run the dry-run again and review the list.";
        }

        if ($orphans > 0 && ! $force && AssetPhoto::query()->doesntExist()) {
            $reasons[] = "the asset_photos table is EMPTY although {$orphans} photo file(s) are on disk — this database is probably not "
                .'the one these files belong to (wrong --env/.env, or a restore that is not finished). Check the target line; '
                .'only if every photo really was deleted, re-run with --force.';
        }

        return $reasons;
    }

    /**
     * @return array{0: list<array{relative: string, real: string, asset_id: int, size: int, mtime: int}>, 1: list<string>, 2: int, 3: ?string}
     */
    private function scan(string $root, int $cutoff, string $directory): array
    {
        $candidates = [];
        $ignored = [];
        $young = 0;
        $scanError = null;

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
                if (preg_match(self::PHOTO_FILE, $relative, $match) !== 1) {
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

                $candidates[] = ['relative' => $relative, 'real' => $real, 'asset_id' => (int) $match[1], 'size' => (int) $entry->getSize(), 'mtime' => $mtime];
            }
        } catch (UnexpectedValueException $e) {
            // never print the server's absolute path — show it relative to the photo root
            $scanError = str_replace($root, $directory, $e->getMessage());
        }

        return [$candidates, $ignored, $young, $scanError];
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

    /**
     * `null` = no cap, `false` = invalid; otherwise a whole number ≥ 1.
     */
    private function parseMaxDelete(mixed $value): int|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/^\d+$/', trim($value)) !== 1 || (int) trim($value) < 1) {
            return false;
        }

        return (int) trim($value);
    }
}
