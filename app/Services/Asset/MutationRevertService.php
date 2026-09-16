<?php

namespace App\Services\Asset;

use App\Models\Asset;
use App\Models\MutationLog;
use App\Models\User;
use App\Support\LocationScope;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mutation revert/undo (Tahap 6.5).
 *
 * The model, exactly as specified: pick a mutation -> compute which asset
 * fields it actually changed (from its own `before_snapshot`/`after_snapshot`,
 * Tahap 5.8.8) -> verify the asset's CURRENT value for each of those fields
 * still equals what the mutation recorded as `after` -> if so, write the
 * `before` values back -> record a NEW `REVERT`/`BATCH_REVERT` mutation log
 * (never touching the original row). If a `batch_operation_id` groups this
 * mutation with others (Tahap 5.8.6/5.8.7 batch edit/delete), the WHOLE group
 * is treated as one atomic revert — every member must be individually
 * revertable and conflict-free, or NONE of them are touched.
 *
 * Conflict detection is field-level, not whole-row (explicit stage
 * requirement): a later mutation to an UNRELATED field never blocks this
 * revert; a later mutation to the SAME field always does. One caveat, which
 * is an accepted limitation of a VALUE-based (not provenance-based) model —
 * the stage's own algorithm, not an extension of it: if a field is changed
 * away and back to the exact same value by some unrelated, later action, a
 * revert of the original mutation cannot tell that apart from "untouched
 * since" and will proceed. Detecting that would require tracking WHICH
 * mutation produced a value, not just comparing values, which the stage does
 * not ask for.
 *
 * Stage 6.9 R5 — authorizes EVERY asset in the group (not just
 * `$mutation`'s own `asset_id`) against `LocationScope`, right after they're
 * resolved and locked but before Pass 1 (validation) or Pass 2 (the actual
 * writes) run. A mutation group can span multiple assets (a prior batch
 * edit/delete's `batch_operation_id`), so checking only the ONE asset
 * `$mutation` points at would miss the others. If any asset in the group is
 * outside the actor's scope, the whole revert aborts (403) before anything
 * is written — `abort()` throws out of the `DB::transaction()` closure
 * below, which rolls back the row locks with it, so nothing partially
 * reverts and no new revert mutation log is created.
 */
class MutationRevertService
{
    /**
     * Snapshot keys never written back to the asset directly: immutable
     * identity (`id`, `asset_code`, `sequence_no` — the latter is `prohibited`
     * on every write endpoint already) or derived/not-a-real-column
     * (`room_name`, resolved FROM `room_id`, never stored itself).
     */
    private const NON_WRITABLE_KEYS = ['id', 'asset_code', 'sequence_no', 'room_name'];

    public function __construct(private readonly AssetMutationRecorder $mutations) {}

    /**
     * @return array{reverted_mutation_ids: list<int>, new_logs: Collection<int, MutationLog>, assets: Collection<int, Asset>}
     */
    public function revert(MutationLog $mutation, User $actor): array
    {
        $group = $this->groupFor($mutation);

        foreach ($group as $row) {
            if (! $row->isRevertable()) {
                abort(409, "Mutasi #{$row->id} tidak dapat direvert (jenis mutasi ini tidak didukung, atau data historisnya tidak lengkap untuk direkonstruksi dengan aman).");
            }
        }

        $assetIds = $group->pluck('asset_id')->unique()->sort()->values()->all();

        return DB::transaction(function () use ($group, $assetIds, $actor): array {
            // Deterministic ascending-id lock order — same convention as
            // AssetWriteService::batchUpdate()/batchSoftDelete() — so two
            // overlapping revert (or revert-vs-batch-edit) requests can never
            // deadlock each other, and no asset's state can change out from
            // under us between the conflict check and the write below.
            $assets = Asset::withTrashed()
                ->with('room')
                ->whereIn('id', $assetIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($assets->count() !== count($assetIds)) {
                abort(404, 'Salah satu aset terkait mutasi ini tidak ditemukan.');
            }

            // Stage 6.9 R5 — authorize EVERY asset in the group before Pass 1
            // even begins. Deliberately NOT based on $mutation->asset_id alone.
            $scope = LocationScope::for($actor);
            foreach ($assets as $asset) {
                if (! $scope->allows($asset->location_code)) {
                    abort(403, 'Anda tidak berwenang membatalkan mutasi ini.');
                }
            }

            // Pass 1 — validate EVERY member of the group before changing
            // anything. If any one fails, the whole group aborts (the
            // transaction rolls back) with nothing written for any of them.
            $plans = [];
            foreach ($group->sortBy('id') as $row) {
                if (MutationLog::query()->where('reverted_mutation_id', $row->id)->exists()) {
                    abort(409, "Mutasi #{$row->id} sudah pernah di-revert sebelumnya.");
                }

                /** @var Asset $asset */
                $asset = $assets->get($row->asset_id);
                $current = $this->mutations->snapshot($asset);
                $changed = $this->changedFields($row->before_snapshot, $row->after_snapshot);

                if ($changed === []) {
                    abort(409, "Mutasi #{$row->id} tidak memiliki perubahan yang dapat direvert.");
                }

                foreach ($changed as $field) {
                    if (! $this->fieldsMatch($field, $current[$field] ?? null, $row->after_snapshot[$field] ?? null)) {
                        abort(409,
                            "Data aset {$asset->asset_code} sudah berubah setelah mutasi #{$row->id} dilakukan. ".
                            'Revert tidak dapat dilakukan secara otomatis. Periksa perubahan terbaru sebelum mencoba tindakan lain.');
                    }
                }

                $plans[] = ['row' => $row, 'asset' => $asset, 'changed' => $changed, 'current' => $current];
            }

            // Pass 2 — apply. Every check above already passed for the WHOLE
            // group, so nothing here can fail for a business reason anymore.
            $batchOperationId = count($plans) > 1 ? (string) Str::uuid() : null;
            $newLogs = collect();
            $updatedAssets = collect();

            foreach ($plans as $plan) {
                /** @var MutationLog $row */
                $row = $plan['row'];
                /** @var Asset $asset */
                $asset = $plan['asset'];
                $before = $plan['current'];

                $this->applyRestoration($asset, $row->before_snapshot, $plan['changed'], $actor);
                $asset->refresh();
                $after = $this->mutations->snapshot($asset);

                $newLogs->push($this->mutations->recordRevert($asset, $row, $before, $after, $actor, $batchOperationId));
                $updatedAssets->push($asset);
            }

            return [
                'reverted_mutation_ids' => $group->pluck('id')->all(),
                'new_logs' => $newLogs,
                'assets' => $updatedAssets,
            ];
        });
    }

    /**
     * The full set of mutation rows to revert together: just `$mutation`
     * itself when it isn't part of a batch, or every row sharing its
     * `batch_operation_id` otherwise (Tahap 5.8.6/5.8.7's own grouping —
     * nothing new invented here).
     *
     * @return Collection<int, MutationLog>
     */
    private function groupFor(MutationLog $mutation): Collection
    {
        if ($mutation->batch_operation_id === null) {
            return collect([$mutation]);
        }

        return MutationLog::query()->where('batch_operation_id', $mutation->batch_operation_id)->get();
    }

    /**
     * Every snapshot key where `before` and `after` actually differ, minus
     * the never-writable ones. This is deliberately generic across every
     * revertable event type (EDIT, MOVE_ROOM, WRITE_OFF, ... all just have
     * some subset of fields differ) rather than a hardcoded field list per
     * event type — a MOVE_ROOM caused by the same request that also changed
     * `brand_model` reverts BOTH, exactly as it happened.
     *
     * @param  ?array<string, mixed>  $before
     * @param  ?array<string, mixed>  $after
     * @return list<string>
     */
    private function changedFields(?array $before, ?array $after): array
    {
        if ($before === null || $after === null) {
            return [];
        }

        $fields = [];
        foreach (array_keys($after) as $key) {
            if (in_array($key, self::NON_WRITABLE_KEYS, true)) {
                continue;
            }
            if (! array_key_exists($key, $before)) {
                continue;
            }
            if ($before[$key] !== $after[$key]) {
                $fields[] = $key;
            }
        }

        return $fields;
    }

    /**
     * `deleted_at` is compared by trashed-ness (null vs not-null), never by
     * exact timestamp — a revert never depends on the asset having been
     * deleted at that EXACT microsecond, only on whether it's currently
     * trashed the way this mutation left it. Every other field is an exact
     * value match.
     */
    private function fieldsMatch(string $field, mixed $current, mixed $expected): bool
    {
        if ($field === 'deleted_at') {
            return ($current === null) === ($expected === null);
        }

        return $current === $expected;
    }

    /**
     * @param  array<string, mixed>  $restoreTo  the mutation's own `before_snapshot`
     * @param  list<string>  $changed
     */
    private function applyRestoration(Asset $asset, array $restoreTo, array $changed, User $actor): void
    {
        // `deleted_at` can't be assigned as a plain attribute — SoftDeletes
        // owns that bookkeeping. `updated_by` is deliberately NOT touched
        // here, matching AssetWriteService::softDelete()/restore() themselves
        // (neither ever bumps it either).
        if (in_array('deleted_at', $changed, true)) {
            if ($restoreTo['deleted_at'] === null) {
                $asset->restore();
            } else {
                $asset->delete();
            }
        }

        $plainFields = array_values(array_diff($changed, ['deleted_at']));
        if ($plainFields !== []) {
            $asset->fill(Arr::only($restoreTo, $plainFields));
            $asset->quantity = 1;
            $asset->updated_by = $actor->id;
            $asset->save();
        }
    }
}
