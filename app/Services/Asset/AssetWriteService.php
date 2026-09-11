<?php

namespace App\Services\Asset;

use App\Enums\MutationEventType;
use App\Http\Requests\Api\BatchDeleteAssetRequest;
use App\Http\Requests\Api\BatchUpdateAssetRequest;
use App\Models\Asset;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Business-critical asset lifecycle operations (Tahap 5.4 §27).
 *
 * The controller stays thin: it validates (FormRequests) and delegates here. This
 * service owns transactions, the sequence generator, concurrency retries, and
 * mutation logging.
 *
 *  - `asset_code` is a DB generated column — never assigned; the model is refreshed
 *    after every write so the Resource emits the current value (§20).
 *  - `sequence_no` is allocated by {@see AssetNumberGenerator} on create and is
 *    NEVER changed afterwards (§10, §19).
 *  - Soft delete / restore keep the same row, id, sequence and asset_code (§7, §23).
 *  - written-off is a business status, independent of soft delete (§8).
 */
class AssetWriteService
{
    /** Descriptive + placement columns a client may set on create/update. */
    private const WRITABLE = [
        'location_code', 'category_code', 'subcategory_code', 'asset_year', 'room_id',
        'condition', 'brand_model', 'serial_no', 'material', 'purchase_date',
        'funding_source', 'detail_type', 'capacity_note', 'notes',
    ];

    public function __construct(
        private readonly AssetNumberGenerator $numbers,
        private readonly AssetMutationRecorder $mutations,
    ) {}

    /**
     * Create an asset with a server-allocated sequence number.
     *
     * @param  array<string, mixed>  $data  validated StoreAssetRequest payload
     */
    public function create(array $data, User $actor): Asset
    {
        $writtenOff = (bool) ($data['is_written_off'] ?? false);

        return $this->withDuplicateRetry(function () use ($data, $actor, $writtenOff): Asset {
            return DB::transaction(function () use ($data, $actor, $writtenOff): Asset {
                $this->numbers->lockScope($data['category_code'], $data['subcategory_code']);

                $asset = new Asset(Arr::only($data, self::WRITABLE));
                $asset->sequence_no = $this->numbers->next(
                    $data['location_code'],
                    $data['category_code'],
                    $data['subcategory_code'],
                );
                $asset->quantity = 1;
                $asset->is_written_off = $writtenOff;
                $asset->written_off_on = $writtenOff ? ($data['written_off_on'] ?? null) : null;
                $asset->written_off_note = $writtenOff ? ($data['written_off_note'] ?? null) : null;
                $asset->created_by = $actor->id;
                $asset->updated_by = $actor->id;
                $asset->save();
                $asset->refresh();

                $this->mutations->record($asset, MutationEventType::Create, null, $this->mutations->snapshot($asset), $actor);

                return $asset;
            });
        });
    }

    /**
     * Create many identical assets in ONE transaction (Tahap 5.8.4).
     *
     *  - Every record has `quantity = 1` — the batch is `count` separate physical
     *    units, never `quantity = count`.
     *  - Each asset's `sequence_no` comes from the SAME {@see AssetNumberGenerator}
     *    used by single create: `lockScope()` is taken once for the whole batch, then
     *    `next()` is called per asset. Because the loop runs inside one transaction on
     *    one connection, each `next()` sees the rows the previous iterations inserted,
     *    so the numbers come out consecutive with no gaps and no separate counter.
     *  - Atomic: any failure rolls the whole batch back to 0 assets. A
     *    duplicate-key / deadlock race retries the whole batch against committed state.
     *  - Every created asset gets a `CREATE` history event sharing one
     *    `batch_operation_id` (Tahap 5.8.8). Recorded AFTER the bulk reload below
     *    (not per-iteration) so `snapshot()` sees the DB-generated `asset_code` and
     *    the eager-loaded `room` relation — one bulk query, not N.
     *
     * @param  array<string, mixed>  $data  validated per-asset template (no `count`)
     * @return Collection<int, Asset> the created assets, fresh
     */
    public function createBatch(array $data, int $count, User $actor): Collection
    {
        return $this->withDuplicateRetry(function () use ($data, $count, $actor): Collection {
            return DB::transaction(function () use ($data, $count, $actor): Collection {
                $this->numbers->lockScope($data['category_code'], $data['subcategory_code']);

                $ids = [];
                for ($i = 0; $i < $count; $i++) {
                    $asset = new Asset(Arr::only($data, self::WRITABLE));
                    $asset->sequence_no = $this->numbers->next(
                        $data['location_code'],
                        $data['category_code'],
                        $data['subcategory_code'],
                    );
                    $asset->quantity = 1;
                    $asset->is_written_off = false;
                    $asset->written_off_on = null;
                    $asset->written_off_note = null;
                    $asset->created_by = $actor->id;
                    $asset->updated_by = $actor->id;
                    $asset->save();

                    $ids[] = $asset->id;
                }

                // one bulk reload so `asset_code` (DB generated column) is populated
                $created = Asset::query()
                    ->with(['location', 'category', 'room'])
                    ->whereIn('id', $ids)
                    ->orderBy('id')
                    ->get();

                Asset::loadSubcategoriesFor($created);

                $batchOperationId = (string) Str::uuid();
                foreach ($created as $asset) {
                    $this->mutations->record(
                        $asset,
                        MutationEventType::Create,
                        null,
                        $this->mutations->snapshot($asset),
                        $actor,
                        batchOperationId: $batchOperationId,
                    );
                }

                return $created;
            });
        });
    }

    /**
     * Update descriptive / placement fields. Never touches `sequence_no` or
     * `asset_code`. Records history (Tahap 5.8.8) iff the asset's relevant state
     * actually differs afterward:
     *  - location/room changed -> `MOVE_ROOM` (§19, §33, unchanged since Tahap 5.4)
     *  - anything else relevant changed (brand_model, condition, category, ...) ->
     *    generic `EDIT`
     *  - nothing relevant changed (a request that resubmits identical values) -> no
     *    event at all, even though `save()` still runs (and still bumps `updated_by`)
     *
     * The before/after comparison is on the full {@see AssetMutationRecorder::snapshot()}
     * arrays, not raw Eloquent dirty-tracking — `updated_by` always changes on save,
     * so comparing snapshots (which exclude it) is what keeps a true no-op silent.
     *
     * @param  array<string, mixed>  $data  validated UpdateAssetRequest payload
     */
    public function update(Asset $asset, array $data, User $actor): Asset
    {
        return DB::transaction(function () use ($asset, $data, $actor): Asset {
            $before = $this->mutations->snapshot($asset);

            $asset->fill(Arr::only($data, self::WRITABLE));
            $asset->quantity = 1;
            $asset->updated_by = $actor->id;
            $asset->save();
            $asset->refresh();

            $after = $this->mutations->snapshot($asset);

            if ($before['location_code'] !== $after['location_code'] || $before['room_id'] !== $after['room_id']) {
                $this->mutations->recordRelocation(
                    $asset,
                    $before,
                    $after,
                    $actor,
                    $data['mutation_note'] ?? null,
                );
            } elseif ($before !== $after) {
                $this->mutations->record($asset, MutationEventType::Edit, $before, $after, $actor);
            }

            return $asset->refresh();
        });
    }

    /**
     * Batch-edit the safe descriptive fields (`room_id`, `condition`, `notes`) across
     * many assets in ONE atomic transaction (Tahap 5.8.6).
     *
     *  - Rows are locked in ascending id order (`lockForUpdate()` + `orderBy('id')`)
     *    — a deterministic lock order across every caller, so two overlapping
     *    batches can never deadlock each other.
     *  - Re-checks the asset set under lock: if any id no longer resolves to a
     *    non-trashed asset (e.g. concurrently soft-deleted after this request's
     *    {@see BatchUpdateAssetRequest} validated it), the
     *    whole batch aborts with 404 rather than silently applying a partial set.
     *  - `room_id` (when present and non-null) is authoritatively re-validated here,
     *    not just in the FormRequest: a room belongs to exactly one location, so it
     *    can only ever be valid when every selected asset shares that location. Any
     *    mismatch fails the WHOLE batch (422) — never a partial move.
     *  - Never touches `location_code`, `asset_code`, `sequence_no`, `quantity`, or
     *    any lifecycle field — those all stay untouched (§ integrity contract).
     *  - Eloquent's dirty-tracking (`isDirty()`) decides "did this asset actually
     *    change": an asset already holding the target value is left completely
     *    unsaved — no `updated_by` bump, no history event. Every asset that DID
     *    change gets exactly one `BATCH_EDIT` history event (Tahap 5.8.8) — a
     *    room-changing one included, on purpose (see {@see MutationEventType}) —
     *    all sharing one `batch_operation_id` for the whole request. The legacy
     *    `type='pindah_ruangan'` column is still set whenever room/location
     *    actually changed, so the original relocation ledger stays correct too.
     *
     * @param  array<int, int>  $assetIds
     * @param  array{room_id?: ?int, condition?: ?string, notes?: ?string}  $changes
     * @return array{requested: int, updated: int, unchanged: int}
     */
    public function batchUpdate(array $assetIds, array $changes, User $actor): array
    {
        $sortedIds = collect($assetIds)->unique()->sort()->values()->all();

        return DB::transaction(function () use ($sortedIds, $changes, $actor): array {
            $assets = Asset::query()
                ->with('room')
                ->whereIn('id', $sortedIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($assets->count() !== count($sortedIds)) {
                abort(404, 'Salah satu aset tidak ditemukan.');
            }

            $targetRoom = null;
            if (array_key_exists('room_id', $changes) && $changes['room_id'] !== null) {
                $targetRoom = Room::query()->find($changes['room_id']);
                $mismatched = $targetRoom === null || $assets->contains(
                    fn (Asset $a): bool => $a->location_code !== $targetRoom->location_code
                );
                if ($mismatched) {
                    throw ValidationException::withMessages([
                        'changes.room_id' => ['Ruangan tidak valid untuk salah satu aset yang dipilih (lokasi berbeda).'],
                    ]);
                }
            }

            $batchOperationId = (string) Str::uuid();
            $updated = 0;

            foreach ($assets as $asset) {
                $before = $this->mutations->snapshot($asset);

                if (array_key_exists('room_id', $changes)) {
                    $asset->room_id = $changes['room_id'];
                }
                if (array_key_exists('condition', $changes)) {
                    $asset->condition = $changes['condition'];
                }
                if (array_key_exists('notes', $changes)) {
                    $asset->notes = $changes['notes'];
                }

                if (! $asset->isDirty()) {
                    continue;
                }

                $asset->updated_by = $actor->id;
                $asset->save();
                // refresh() also reloads the eager-loaded `room` relation against the
                // now-saved room_id, so the "after" snapshot's room_name is correct
                // with no manual relation juggling needed.
                $asset->refresh();
                $updated++;

                $after = $this->mutations->snapshot($asset);
                if ($before['room_id'] !== $after['room_id']) {
                    $this->mutations->recordRelocation($asset, $before, $after, $actor, batchOperationId: $batchOperationId);
                } else {
                    $this->mutations->record($asset, MutationEventType::BatchEdit, $before, $after, $actor, batchOperationId: $batchOperationId);
                }
            }

            return [
                'requested' => count($sortedIds),
                'updated' => $updated,
                'unchanged' => count($sortedIds) - $updated,
            ];
        });
    }

    /** Soft delete. The row, its number and its mutation history all remain (§22, §36). */
    public function softDelete(Asset $asset, User $actor): void
    {
        DB::transaction(function () use ($asset, $actor): void {
            $before = $this->mutations->snapshot($asset);
            $asset->delete();
            $after = $this->mutations->snapshot($asset);
            $this->mutations->record($asset, MutationEventType::SoftDelete, $before, $after, $actor);
        });
    }

    /**
     * Soft-delete many assets in ONE atomic transaction (Tahap 5.8.7).
     *
     *  - Same locking strategy as {@see self::batchUpdate()}: rows are locked in
     *    ascending id order so overlapping batches can never deadlock each other.
     *  - Re-checks the locked count under lock: if any id no longer resolves to a
     *    non-trashed asset (concurrently soft-deleted after
     *    {@see BatchDeleteAssetRequest} validated it), the
     *    whole batch aborts with 404 — never a partial delete.
     *  - Just like the single-asset `softDelete()`, this never touches `updated_by`
     *    or `is_written_off`/`written_off_*`. Every deleted asset gets a
     *    `BATCH_DELETE` history event (Tahap 5.8.8) sharing one `batch_operation_id`
     *    for the whole request.
     *
     * @param  array<int, int>  $assetIds
     * @return array{requested: int, deleted: int}
     */
    public function batchSoftDelete(array $assetIds, User $actor): array
    {
        $sortedIds = collect($assetIds)->unique()->sort()->values()->all();

        return DB::transaction(function () use ($sortedIds, $actor): array {
            $assets = Asset::query()
                ->with('room')
                ->whereIn('id', $sortedIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($assets->count() !== count($sortedIds)) {
                abort(404, 'Salah satu aset tidak ditemukan.');
            }

            $batchOperationId = (string) Str::uuid();

            foreach ($assets as $asset) {
                $before = $this->mutations->snapshot($asset);
                $asset->delete();
                $after = $this->mutations->snapshot($asset);
                $this->mutations->record($asset, MutationEventType::BatchDelete, $before, $after, $actor, batchOperationId: $batchOperationId);
            }

            return [
                'requested' => count($sortedIds),
                'deleted' => $assets->count(),
            ];
        });
    }

    /**
     * Restore a soft-deleted asset. Same id / sequence / asset_code, no new number
     * consumed, no duplicate created — the row already owns its identity slot in
     * `uq_assets_number` (which counts trashed rows), so a collision is impossible
     * unless the DB was tampered with directly (§23, §37). A no-op restore (already
     * active) records no history — there is nothing to audit.
     */
    public function restore(Asset $asset, User $actor): Asset
    {
        if (! $asset->trashed()) {
            return $asset->refresh();
        }

        return $this->withDuplicateRetry(function () use ($asset, $actor): Asset {
            return DB::transaction(function () use ($asset, $actor): Asset {
                $before = $this->mutations->snapshot($asset);
                $asset->restore();
                $asset->refresh();
                $after = $this->mutations->snapshot($asset);
                $this->mutations->record($asset, MutationEventType::Restore, $before, $after, $actor);

                return $asset;
            });
        }, attempts: 1);
    }

    /**
     * Mark written-off. Physical `condition` is left untouched (§8, §18, §24, §35).
     *
     * @param  array{written_off_on:string, written_off_note?:string|null}  $data
     */
    public function writeOff(Asset $asset, array $data, User $actor): Asset
    {
        if ($asset->is_written_off) {
            abort(409, 'Aset sudah berstatus written-off.');
        }

        return DB::transaction(function () use ($asset, $data, $actor): Asset {
            $before = $this->mutations->snapshot($asset);

            $asset->is_written_off = true;
            $asset->written_off_on = $data['written_off_on'];
            $asset->written_off_note = $data['written_off_note'] ?? null;
            $asset->updated_by = $actor->id;
            $asset->save();
            $asset->refresh();

            $after = $this->mutations->snapshot($asset);
            $this->mutations->record($asset, MutationEventType::WriteOff, $before, $after, $actor);

            return $asset;
        });
    }

    /** Clear written-off status, date and note (§8, §35). */
    public function unwriteOff(Asset $asset, User $actor): Asset
    {
        if (! $asset->is_written_off) {
            abort(409, 'Aset tidak berstatus written-off.');
        }

        return DB::transaction(function () use ($asset, $actor): Asset {
            $before = $this->mutations->snapshot($asset);

            $asset->is_written_off = false;
            $asset->written_off_on = null;
            $asset->written_off_note = null;
            $asset->updated_by = $actor->id;
            $asset->save();
            $asset->refresh();

            $after = $this->mutations->snapshot($asset);
            $this->mutations->record($asset, MutationEventType::UnwriteOff, $before, $after, $actor);

            return $asset;
        });
    }

    /**
     * Retry a closure a few times when it hits a duplicate-key / deadlock / lock-wait
     * race — each retry re-runs the generator against the now-committed state (§12).
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function withDuplicateRetry(callable $operation, int $attempts = 4): mixed
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $operation();
            } catch (QueryException $e) {
                if (! $this->isRetryable($e)) {
                    throw $e;
                }
                $lastException = $e;
                usleep(random_int(5_000, 25_000));
            }
        }

        throw $lastException;
    }

    private function isRetryable(QueryException $e): bool
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        // 1062 duplicate entry · 1213 deadlock · 1205 lock wait timeout
        return in_array($driverCode, [1062, 1213, 1205], true);
    }
}
