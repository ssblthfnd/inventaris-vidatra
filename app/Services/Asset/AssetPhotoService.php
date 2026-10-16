<?php

namespace App\Services\Asset;

use App\Enums\MutationEventType;
use App\Models\Asset;
use App\Models\AssetPhoto;
use App\Models\User;
use App\Services\Image\ImageRejectedException;
use App\Services\Image\ImageSanitizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Foto Aset — keeps `asset_photos` rows and the private JPEG files consistent.
 * Authorization happens one layer up (AssetPhotoController + AssetPolicy);
 * image validation/processing is {@see ImageSanitizer}'s job.
 *
 * Upload ({@see self::store()}):
 *   sanitize → write `asset-photos/{asset_id}/{uuid}.jpg` → transaction { lock
 *   the asset row (FOR UPDATE; a soft-deleted asset is not found → 404) →
 *   re-check AssetPolicy::update on that locked row (403) → count
 *   photos UNDER the lock → pick the lowest free slot → insert the row → record
 *   PHOTO_ADDED } → commit. Any failure after the file was written deletes THAT
 *   new file again (never any other); if even that fails it is logged and left
 *   for `assets:photos:prune-orphans`. The asset-row lock serialises every
 *   upload/delete of one asset, so two uploads racing for the last slot cannot
 *   both succeed; `UNIQUE (asset_id, slot)` is the database's own backstop, and
 *   a violation of it is answered with 409, never 500.
 *
 * Delete ({@see self::delete()}):
 *   transaction { lock the asset row, re-check AssetPolicy::update on it (403),
 *   then lock the photo row (same order as upload, so the two never deadlock)
 *   → record PHOTO_DELETED → delete the row } →
 *   commit → THEN delete the file. A file that cannot be deleted is logged and
 *   becomes an orphan for the prune command; the row is already gone, so the
 *   photo is no longer reachable either way. Deletion is permanent.
 *
 * Neither path ever touches another photo's file or row, and neither writes
 * image bytes, base64 or the private path into mutation history.
 */
class AssetPhotoService
{
    /** Hard upper bound — matches the `chk_asset_photos_slot` CHECK constraint. */
    public const MAX_SLOT = 3;

    public function __construct(
        private readonly ImageSanitizer $sanitizer,
        private readonly AssetMutationRecorder $mutations,
    ) {}

    public static function maxPerAsset(): int
    {
        return max(1, min(self::MAX_SLOT, (int) config('inventory.photos.max_per_asset', self::MAX_SLOT)));
    }

    public function disk(): FilesystemAdapter
    {
        return Storage::disk((string) config('inventory.photos.disk', 'local'));
    }

    public static function directory(): string
    {
        return trim((string) config('inventory.photos.directory', 'asset-photos'), '/');
    }

    /**
     * @throws ValidationException 422 — the image was rejected, or the asset already has the maximum
     */
    public function store(Asset $asset, UploadedFile $file, User $actor): AssetPhoto
    {
        // Cheap early answer for the common case (before spending CPU on the
        // image); the authoritative check is the one under the row lock below.
        $this->assertHasFreeSlot(AssetPhoto::query()->where('asset_id', $asset->id)->count());

        try {
            $image = $this->sanitizer->sanitize((string) $file->getRealPath());
        } catch (ImageRejectedException $e) {
            throw ValidationException::withMessages(['photo' => [$e->getMessage()]]);
        }

        $path = self::directory().'/'.$asset->id.'/'.Str::uuid()->toString().'.jpg';

        if (! $this->writeFile($path, $image->bytes)) {
            Log::error('asset_photo.write_failed', ['asset_id' => $asset->id]);
            $this->deleteFile($path, $asset->id);
            abort(500, 'Foto gagal disimpan di server. Coba lagi beberapa saat lagi.');
        }

        try {
            return DB::transaction(function () use ($asset, $image, $path, $actor): AssetPhoto {
                $locked = Asset::query()->whereKey($asset->id)->lockForUpdate()->first();
                if ($locked === null) {
                    abort(404); // soft-deleted (or gone) since the request was routed
                }
                $this->authorizeLocked($actor, $locked);

                $usedSlots = AssetPhoto::query()->where('asset_id', $locked->id)->pluck('slot')->map(fn ($s) => (int) $s)->all();
                $this->assertHasFreeSlot(count($usedSlots));
                $slot = min(array_diff(range(1, self::maxPerAsset()), $usedSlots));

                $photo = AssetPhoto::query()->create([
                    'asset_id' => $locked->id,
                    'slot' => $slot,
                    'path' => $path,
                    'mime' => $image->mime(),
                    'size_bytes' => $image->sizeBytes(),
                    'width' => $image->width,
                    'height' => $image->height,
                    'uploaded_by' => $actor->id,
                ]);

                $snapshot = $this->mutations->snapshot($locked);
                $this->mutations->record(
                    $locked,
                    MutationEventType::PhotoAdded,
                    $snapshot,
                    $snapshot,
                    $actor,
                    note: $this->summary($photo, 'ditambahkan'),
                );

                return $photo;
            });
        } catch (UniqueConstraintViolationException) {
            $this->deleteFile($path, $asset->id);
            abort(409, 'Slot foto baru saja terisi oleh unggahan lain. Muat ulang daftar foto lalu coba lagi.');
        } catch (Throwable $e) {
            $this->deleteFile($path, $asset->id);
            throw $e;
        }
    }

    /**
     * Permanently delete one photo of `$asset`. The caller has already
     * scope-bound `$photo` to `$asset` (route `scopeBindings()`); the row is
     * re-read under lock with the same constraint.
     */
    public function delete(Asset $asset, AssetPhoto $photo, User $actor): void
    {
        $path = DB::transaction(function () use ($asset, $photo, $actor): string {
            $locked = Asset::query()->whereKey($asset->id)->lockForUpdate()->first();
            if ($locked === null) {
                abort(404);
            }
            $this->authorizeLocked($actor, $locked);

            $row = AssetPhoto::query()
                ->whereKey($photo->id)
                ->where('asset_id', $locked->id)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                abort(404); // already deleted by a concurrent request
            }

            $snapshot = $this->mutations->snapshot($locked);
            $this->mutations->record(
                $locked,
                MutationEventType::PhotoDeleted,
                $snapshot,
                $snapshot,
                $actor,
                note: $this->summary($row, 'dihapus permanen'),
            );

            $row->delete();

            return $row->path;
        });

        $this->deleteFile($path, $asset->id);
    }

    /* ------------------------------------------------------------------ helpers */

    /**
     * Re-evaluates AssetPolicy::update against the row just read under
     * `FOR UPDATE` — i.e. the asset's CURRENT location, not the copy the
     * controller authorized before the transaction. If the asset was moved out
     * of the actor's scope in between (e.g. a global admin relocated it), the
     * write is refused with the same 403 as the controller's own check.
     * Nothing else can move it any more until this transaction ends.
     */
    private function authorizeLocked(User $actor, Asset $locked): void
    {
        if (Gate::forUser($actor)->denies('update', $locked)) {
            abort(403);
        }
    }

    private function assertHasFreeSlot(int $used): void
    {
        $max = self::maxPerAsset();
        if ($used >= $max) {
            throw ValidationException::withMessages([
                'photo' => ["Aset ini sudah memiliki {$max} foto (batas maksimal). Hapus salah satu foto sebelum menambahkan foto baru."],
            ]);
        }
    }

    /** e.g. "Foto slot 2 ditambahkan (1600×1200, 312 KB)" — metadata only, never the path. */
    private function summary(AssetPhoto $photo, string $verb): string
    {
        $kb = max(1, (int) round($photo->size_bytes / 1024));

        return "Foto slot {$photo->slot} {$verb} ({$photo->width}×{$photo->height}, {$kb} KB)";
    }

    private function writeFile(string $path, string $bytes): bool
    {
        try {
            return $this->disk()->put($path, $bytes) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Best effort: a failure is logged (asset id + file name only, server log)
     * and the file is left for `assets:photos:prune-orphans`.
     */
    private function deleteFile(string $path, int $assetId): void
    {
        try {
            $disk = $this->disk();
            if ($disk->delete($path) || ! $disk->exists($path)) {
                return;
            }
        } catch (Throwable) {
            // fall through to the log line below
        }

        Log::warning('asset_photo.file_delete_failed', [
            'asset_id' => $assetId,
            'file' => basename($path),
            'hint' => 'php artisan assets:photos:prune-orphans',
        ]);
    }
}
