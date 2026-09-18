<?php

namespace App\Import\RoomMapping;

use App\Import\Parsing\ValueNormalizer;
use App\Models\ImportBatch;
use App\Models\User;
use App\Support\LocationScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Tahap 6.9 R9.2 — resolves `room_unmapped` staged rows to a real room, either
 * for the current batch only or permanently (as a new `room_aliases` row).
 *
 * Deliberately does NOT touch {@see \App\Import\Matching\RoomMatcher} (still
 * exact-name -> alias -> none, unchanged) or {@see
 * \App\Import\Promotion\AssetPromoter} (still reads `import_rows.matched_room_id`
 * at promotion time, unchanged — see that class's `promoteOne()`). This class
 * only ever updates `import_rows` rows that are NOT yet promoted and NOT yet
 * mapped, so its effect is always visible to a promotion that runs afterward,
 * matching the required "resolve BEFORE promote" workflow without either class
 * needing to know the other exists.
 *
 * Authorization is deliberately LOCATION-scoped, not ownership-scoped — the
 * SAME precedent {@see \App\Import\Promotion\AssetPromoter::promoteBatch()}
 * already established for the identical "colocated unit_admins share a batch"
 * scenario (Stage 6.9 R6, re-affirmed as R8.1 finding P2-2): a batch has no
 * single owner-only meaning once it's staged, so `ImportBatchPolicy`
 * (ownership-based) is intentionally NOT used here, exactly like `promote()`
 * skips it. Every method re-derives {@see LocationScope} fresh from the
 * current actor, never trusting a cached/frontend-supplied scope.
 */
final class RoomMappingResolver
{
    /**
     * Distinct (location_code, normalized raw value) groups this actor may see —
     * silently narrowed to the actor's own location for `unit_admin` (never a
     * 403 — an out-of-scope group simply doesn't appear, same "filter, don't
     * reject" convention {@see \App\Http\Controllers\Api\Concerns\FiltersAssets}
     * already uses for reads). Only rows that could still become a mapped
     * asset are counted: not yet promoted, not yet mapped, a real non-blank raw
     * value, and `validation_status = 'warning'` specifically — a row that is
     * `error` for some OTHER reason (e.g. invalid category) will never be
     * promoted regardless of its room, so including it would overstate
     * "affected assets".
     *
     * @return list<array{location_code:string,raw_value:string,match_key:string,affected_rows:int}>
     */
    public function unmappedGroups(ImportBatch $batch, User $actor): array
    {
        $scope = LocationScope::for($actor);
        $allowedCodes = $scope->resolveFilterCodes([]); // null = unrestricted (global actor)

        $rows = DB::table('import_rows')
            ->where('import_batch_id', $batch->id)
            ->where('validation_status', 'warning')
            ->whereNull('matched_room_id')
            ->whereNull('promoted_asset_id')
            ->whereNotNull('location_code')
            ->whereNotNull('room_raw_value')
            ->where('room_raw_value', '!=', '')
            ->when($allowedCodes !== null, fn ($q) => $q->whereIn('location_code', $allowedCodes))
            ->orderBy('row_number')
            ->get(['location_code', 'room_raw_value']);

        $groups = [];
        foreach ($rows as $row) {
            $key = ValueNormalizer::roomMatchKey($row->room_raw_value);
            $groupKey = $row->location_code."\x1f".$key;
            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'location_code' => $row->location_code,
                    'raw_value' => $row->room_raw_value, // first-seen representative, kept verbatim for reference
                    'match_key' => $key,
                    'affected_rows' => 0,
                ];
            }
            $groups[$groupKey]['affected_rows']++;
        }

        return array_values($groups);
    }

    /**
     * Resolves ONE (location_code, raw_value) group to `$roomId`, for this
     * batch only or permanently. Atomic per call (one transaction covering the
     * optional alias insert + the staged-row update together — never the whole
     * batch): every check below re-reads current DB state under lock, so a
     * room deactivated/reassigned or a scope changed between the GET list and
     * this call is caught here, not assumed from stale frontend data.
     *
     * @return array{location_code:string,match_key:string,room:array{id:int,name:string},updated_rows:int,alias_created:bool,alias_already_existed:bool}
     *
     * @throws AuthorizationException  actor has no scope for `$locationCode` (403)
     * @throws RuntimeException        room/alias business-rule violation (caller maps this to 422)
     */
    public function resolve(
        ImportBatch $batch,
        string $locationCode,
        string $rawValue,
        int $roomId,
        bool $saveAsAlias,
        User $actor,
    ): array {
        $scope = LocationScope::for($actor);
        if (! $scope->allows($locationCode)) {
            throw new AuthorizationException(
                "Actor is not authorized for location '{$locationCode}'."
            );
        }

        $matchKey = ValueNormalizer::roomMatchKey($rawValue);

        return DB::transaction(function () use ($batch, $locationCode, $rawValue, $matchKey, $roomId, $saveAsAlias, $actor) {
            $room = DB::table('rooms')->where('id', $roomId)->lockForUpdate()->first();
            if ($room === null) {
                throw new RuntimeException('Ruangan tujuan tidak ditemukan.');
            }
            if ($room->location_code !== $locationCode) {
                throw new RuntimeException('Ruangan tujuan harus berada di lokasi yang sama dengan nilai yang dipetakan.');
            }
            if (! $room->is_active) {
                throw new RuntimeException('Ruangan tujuan sudah tidak aktif.');
            }

            $aliasCreated = false;
            $aliasAlreadyExisted = false;

            if ($saveAsAlias) {
                $existingAlias = DB::table('room_aliases')
                    ->where('location_code', $locationCode)
                    ->where('match_key', $matchKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingAlias !== null) {
                    if ((int) $existingAlias->room_id !== $roomId) {
                        throw new RuntimeException(
                            'Sudah ada alias untuk nilai ini di lokasi tersebut, mengarah ke ruangan lain. '
                            .'Gunakan ruangan itu sebagai target, atau perbarui/hapus alias yang ada terlebih dahulu.'
                        );
                    }
                    // Same value already aliased to the SAME room — nothing to create,
                    // never silently overwritten, never duplicated (unique constraint
                    // uq_room_aliases_location_match_key backs this up either way).
                    $aliasAlreadyExisted = true;
                } else {
                    $now = now();
                    DB::table('room_aliases')->insert([
                        'location_code' => $locationCode,
                        'raw_value' => $rawValue,
                        'match_key' => $matchKey,
                        'room_id' => $roomId,
                        'source' => 'manual',
                        'notes' => null,
                        'created_by' => $actor->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $aliasCreated = true;
                }
            }

            // Re-select under the batch's current state (not the GET list's
            // possibly-stale snapshot): only still-unresolved, still-unpromoted,
            // warning-status rows in this exact (batch, location) whose raw
            // value normalizes to the same key. Matching by match_key (not the
            // literal raw string) is deliberate — it is the same identity a
            // permanent alias would resolve by, so a batch containing both
            // "LAB KOMPUTER" and "Lab  Komputer" (different casing/spacing,
            // same normalized key) resolves together in one action.
            $candidateRows = DB::table('import_rows')
                ->where('import_batch_id', $batch->id)
                ->where('location_code', $locationCode)
                ->where('validation_status', 'warning')
                ->whereNull('matched_room_id')
                ->whereNull('promoted_asset_id')
                ->whereNotNull('room_raw_value')
                ->lockForUpdate()
                ->get(['id', 'room_raw_value']);

            $ids = $candidateRows
                ->filter(fn ($r) => ValueNormalizer::roomMatchKey($r->room_raw_value) === $matchKey)
                ->pluck('id')
                ->all();

            $updated = 0;
            if ($ids !== []) {
                $updated = DB::table('import_rows')
                    ->whereIn('id', $ids)
                    ->update([
                        'matched_room_id' => $roomId,
                        'room_match_method' => 'alias',
                        'updated_at' => now(),
                    ]);
            }

            return [
                'location_code' => $locationCode,
                'match_key' => $matchKey,
                'room' => ['id' => $room->id, 'name' => $room->name],
                'updated_rows' => $updated,
                'alias_created' => $aliasCreated,
                'alias_already_existed' => $aliasAlreadyExisted,
            ];
        });
    }
}
