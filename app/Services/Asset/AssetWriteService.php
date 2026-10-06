<?php

namespace App\Services\Asset;

use App\Enums\MutationEventType;
use App\Http\Requests\Api\BatchDeleteAssetRequest;
use App\Http\Requests\Api\BatchUpdateAssetRequest;
use App\Import\Validation\DuplicateChecker;
use App\Models\Asset;
use App\Models\Room;
use App\Models\User;
use App\Support\LocationScope;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
 *
 * Stage 6.9 R5 — `batchUpdate()`/`batchSoftDelete()` authorize the COMPLETE
 * resolved+locked asset set (every unique `location_code` present) BEFORE
 * any row in the batch is mutated or any mutation log is written: if even
 * one targeted asset is outside the actor's `LocationScope`, the whole
 * request aborts (403) and nothing changes — no partial batch. Single-asset
 * actions (`create`/`update`/`softDelete`/`restore`/`writeOff`/`unwriteOff`)
 * are authorized one layer up, in `AssetController`, via `AssetPolicy` —
 * there's no existing-row lock for those to piggyback on, so checking in
 * the controller before this service is ever called is equally safe.
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
        private readonly DuplicateChecker $duplicates,
    ) {}

    /**
     * Create an asset with a server-allocated sequence number.
     *
     * @param  array<string, mixed>  $data  validated StoreAssetRequest payload
     */
    public function create(array $data, User $actor): Asset
    {
        return $this->withDuplicateRetry(function () use ($data, $actor): Asset {
            return DB::transaction(function () use ($data, $actor): Asset {
                $this->numbers->lockScope($data['category_code'], $data['subcategory_code']);

                $asset = $this->newAsset($data, $this->nextSequence($data), $actor);
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

                // the template never carries written-off fields (prohibited by
                // BatchStoreAssetRequest); dropped here too so a batch can never
                // create a disposed asset
                $template = Arr::except($data, ['is_written_off', 'written_off_on', 'written_off_note']);

                $ids = [];
                for ($i = 0; $i < $count; $i++) {
                    $asset = $this->newAsset($template, $this->nextSequence($template), $actor);
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
     * Create independent assets — each its own fields, each optionally with a
     * manually entered number — in ONE transaction (Tahap 6.9 R10,
     * `POST /api/assets/entries`). The caller has already validated every item
     * and authorized every location.
     *
     *  1. Every numbering scope (category, subcategory) the request touches is
     *     locked with {@see AssetNumberGenerator::lockScope()}, in sorted order so
     *     two concurrent requests can never deadlock on each other's locks.
     *  2. Manual numbers are checked against existing assets (soft-deleted
     *     included) UNDER those locks. A conflict is a 422 on that row
     *     (`items.N.sequence_no`), thrown out of the transaction — never treated
     *     as a retryable race.
     *  3. Manual-number assets are inserted first, verbatim; only then are the
     *     automatic numbers allocated with {@see AssetNumberGenerator::next()},
     *     which therefore already sees every manual number of this request and can
     *     never hand one of them out again.
     *  4. One CREATE history event per asset — sharing a `batch_operation_id`
     *     when the request creates more than one (the same "group only when > 1"
     *     rule as {@see MutationRevertService}).
     *
     * Atomic: any failure rolls back every asset and every history event. A
     * duplicate-key / deadlock / lock-wait race (e.g. the importer, which does not
     * take these locks, committing the same identity meanwhile) retries the whole
     * request against committed state, where step 2 then reports it as a 422.
     *
     * @param  list<array<string, mixed>>  $items  validated StoreAssetEntriesRequest items
     * @return EloquentCollection<int, Asset> the created assets, fresh, in `$items` order
     *
     * @throws ValidationException a manual number is already taken
     */
    public function createEntries(array $items, User $actor): EloquentCollection
    {
        return $this->withDuplicateRetry(function () use ($items, $actor): EloquentCollection {
            return DB::transaction(function () use ($items, $actor): EloquentCollection {
                $scopes = [];
                foreach ($items as $item) {
                    $scopes[$item['category_code']."\x1f".$item['subcategory_code']] = [$item['category_code'], $item['subcategory_code']];
                }
                ksort($scopes, SORT_STRING);
                foreach ($scopes as [$categoryCode, $subcategoryCode]) {
                    $this->numbers->lockScope($categoryCode, $subcategoryCode);
                }

                $manual = [];
                $automatic = [];
                foreach ($items as $index => $item) {
                    if (($item['sequence_no'] ?? null) !== null) {
                        $manual[$index] = $item;
                    } else {
                        $automatic[$index] = $item;
                    }
                }

                $taken = [];
                foreach ($manual as $index => $item) {
                    if ($this->existingIdentity($item) !== null) {
                        $taken["items.{$index}.sequence_no"] = ['Nomor inventaris sudah digunakan.'];
                    }
                }
                if ($taken !== []) {
                    throw ValidationException::withMessages($taken);
                }

                /** @var array<int, int> $idsByIndex */
                $idsByIndex = [];
                foreach ($manual as $index => $item) {
                    $asset = $this->newAsset($item, (string) $item['sequence_no'], $actor);
                    $this->saveManual($asset, $item, $index, $idsByIndex);
                    $idsByIndex[$index] = $asset->id;
                }
                foreach ($automatic as $index => $item) {
                    $asset = $this->newAsset($item, $this->nextSequence($item), $actor);
                    $asset->save();
                    $idsByIndex[$index] = $asset->id;
                }
                ksort($idsByIndex);

                // one bulk reload so `asset_code` (DB generated column) is populated,
                // then back into request order — insertion order never leaks out
                $loaded = Asset::query()
                    ->with(['location', 'category', 'room'])
                    ->whereIn('id', $idsByIndex)
                    ->get()
                    ->keyBy('id');
                $created = new EloquentCollection(array_map(fn (int $id): Asset => $loaded[$id], array_values($idsByIndex)));

                Asset::loadSubcategoriesFor($created);

                $batchOperationId = $created->count() > 1 ? (string) Str::uuid() : null;
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
     * Insert a manual-number asset. The identity was checked free under the
     * numbering lock, so a duplicate key here means either another row of THIS
     * request names the same identity in a way only the database collation
     * equates (e.g. accents) — a 422 on this row — or something outside the lock
     * (the importer) committed it meanwhile — rethrown for the retry, whose
     * re-check then reports it.
     *
     * @param  array<string, mixed>  $item
     * @param  array<int, int>  $insertedIds  index => id of this request's assets so far
     */
    private function saveManual(Asset $asset, array $item, int $index, array $insertedIds): void
    {
        try {
            $asset->save();
        } catch (QueryException $e) {
            $existingId = (int) ($e->errorInfo[1] ?? 0) === 1062 ? $this->existingIdentity($item) : null;
            $otherIndex = $existingId !== null ? array_search($existingId, $insertedIds, true) : false;
            if ($otherIndex === false) {
                throw $e;
            }

            throw ValidationException::withMessages([
                "items.{$index}.sequence_no" => ['Nomor inventaris yang sama juga diisi pada baris '.($otherIndex + 1).'.'],
            ]);
        }
    }

    /** @param  array<string, mixed>  $item */
    private function existingIdentity(array $item): ?int
    {
        return $this->duplicates->existingAssetIdFor(
            (string) $item['location_code'],
            (string) $item['category_code'],
            (string) $item['subcategory_code'],
            (string) $item['sequence_no'],
            (int) $item['asset_year'],
        );
    }

    /**
     * A new, unsaved asset from validated create data — the one place `create()`,
     * `createBatch()` and `createEntries()` build an asset, so they can never drift
     * apart. `quantity` is always 1; written-off fields only apply when
     * `is_written_off` is set.
     *
     * @param  array<string, mixed>  $data
     */
    private function newAsset(array $data, string $sequenceNo, User $actor): Asset
    {
        $writtenOff = (bool) ($data['is_written_off'] ?? false);

        $asset = new Asset(Arr::only($data, self::WRITABLE));
        $asset->sequence_no = $sequenceNo;
        $asset->quantity = 1;
        $asset->is_written_off = $writtenOff;
        $asset->written_off_on = $writtenOff ? ($data['written_off_on'] ?? null) : null;
        $asset->written_off_note = $writtenOff ? ($data['written_off_note'] ?? null) : null;
        $asset->created_by = $actor->id;
        $asset->updated_by = $actor->id;

        return $asset;
    }

    /**
     * The next generated number for the data's numbering family. The caller holds
     * {@see AssetNumberGenerator::lockScope()} for it, inside the same transaction.
     *
     * @param  array<string, mixed>  $data
     */
    private function nextSequence(array $data): string
    {
        return $this->numbers->next($data['location_code'], $data['category_code'], $data['subcategory_code']);
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

            // Stage 6.9 R5 — authorize the WHOLE target set before anything
            // else (including before the room↔location check below, which
            // only validates data consistency, not actor authorization). One
            // out-of-scope asset aborts the entire batch — nothing is mutated,
            // no mutation log is written, and the transaction this closure
            // runs in rolls back automatically when abort() throws.
            $scope = LocationScope::for($actor);
            foreach ($assets as $asset) {
                if (! $scope->allows($asset->location_code)) {
                    abort(403, 'Anda tidak berwenang mengubah salah satu aset yang dipilih.');
                }
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

            // Stage 6.9 R5 — same all-or-nothing authorization as batchUpdate()
            // above: the complete target set is checked before any delete.
            $scope = LocationScope::for($actor);
            foreach ($assets as $asset) {
                if (! $scope->allows($asset->location_code)) {
                    abort(403, 'Anda tidak berwenang menghapus salah satu aset yang dipilih.');
                }
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
