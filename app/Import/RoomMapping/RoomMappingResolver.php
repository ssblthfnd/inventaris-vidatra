<?php

namespace App\Import\RoomMapping;

use App\Import\Parsing\ValueNormalizer;
use App\Import\Validation\RowValidation;
use App\Models\ImportBatch;
use App\Models\User;
use App\Policies\ImportBatchPolicy;
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
 * Authorization is LOCATION-scoped, not ownership-scoped — the same
 * precedent {@see \App\Import\Promotion\AssetPromoter::promoteBatch()}
 * established for the "colocated unit_admins share a batch" scenario (Stage
 * 6.9 R6, re-affirmed as R8.1 finding P2-2). Since Tahap 6.9 R9.4-07 (D2) the
 * batch-level rule is the shared {@see ImportBatchPolicy::batchWithinScope()}
 * (the whole batch must be inside the actor's scope — a mixed, foreign or
 * location-less batch is out of reach for a unit_admin), checked by the
 * controller for `unmappedGroups()` and by `resolve()` itself. Every method
 * re-derives {@see LocationScope} fresh from the current actor, never trusting
 * a cached/frontend-supplied scope.
 */
final class RoomMappingResolver
{
    /** Tahap 6.9 R9.4-02 — `import_rows.room_match_method` values written by this class. */
    public const METHOD_MANUAL = 'manual';

    public const METHOD_MANUAL_ALIAS = 'manual_alias';

    /**
     * Distinct (location_code, normalized raw value) groups this actor may see.
     * The caller has already passed {@see ImportBatchPolicy} (R9.4-07 D2), so
     * for a `unit_admin` every located row is in its own location; the narrowing
     * below is kept as a second line of defence. Only rows that could still become a mapped
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
     * @return array{location_code:string,match_key:string,room:array{id:int,name:string},room_match_method:string,updated_rows:int,rows_became_valid:int,alias_created:bool,alias_already_existed:bool,resolution:array{id:int,resolved_by:array{id:int,name:string},resolved_at:string}|null}
     *
     * @throws AuthorizationException  batch not entirely within the actor's scope, actor has no scope for `$locationCode`, or `$saveAsAlias` without `roomAliases.resolve` (403)
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

        // Tahap 6.9 R9.4-07 (D2) — the same whole-batch boundary as viewing and
        // promotion, before anything else is checked or written: a unit_admin
        // can no longer resolve its own location's group inside a mixed batch,
        // nor reach a foreign batch by claiming its own location in the payload.
        if (! ImportBatchPolicy::batchWithinScope($batch->id, $scope)) {
            throw new AuthorizationException(
                "Batch {$batch->id} contains data outside your assigned location."
            );
        }

        if (! $scope->allows($locationCode)) {
            throw new AuthorizationException(
                "Actor is not authorized for location '{$locationCode}'."
            );
        }

        // Stage 6.9 R9.3 — persisting a PERMANENT alias is its own WHAT:
        // `roomAliases.resolve`, deliberately narrower than the generic
        // `roomAliases.manage` (which unit_admin does not hold). WHERE is the
        // scope check above, which already ran for this exact location —
        // an alias can only ever be created for a location the actor may
        // map, and only through this import room-mapping path. Checked
        // before the transaction so nothing (alias OR staged-row update) is
        // written when it fails. A batch-only mapping (`$saveAsAlias=false`)
        // needs only the route's `assets.import`, as before.
        if ($saveAsAlias && $actor->cannot('roomAliases.resolve')) {
            throw new AuthorizationException('Actor may not save a permanent room alias.');
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
            $aliasId = null;

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
                    $aliasId = (int) $existingAlias->id;
                } else {
                    $now = now();
                    $aliasId = DB::table('room_aliases')->insertGetId([
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
                ->get(['id', 'room_raw_value', 'validation_status', 'validation_messages']);

            $rows = $candidateRows
                ->filter(fn ($r) => ValueNormalizer::roomMatchKey($r->room_raw_value) === $matchKey)
                ->values();

            // Tahap 6.9 R9.4-02 — the method records where THIS matched_room_id came
            // from: a user's pick, backed by a permanent alias or not. Never `alias`
            // (that value means RoomMatcher found an alias automatically at staging).
            $method = $saveAsAlias ? self::METHOD_MANUAL_ALIAS : self::METHOD_MANUAL;

            $resolution = null;
            $updated = 0;
            $becameValid = 0;

            // Nothing left to map (e.g. a stale UI resubmitting an already-resolved
            // group): no row changed, so no resolution is recorded. A permanent alias
            // created above still stands on its own `created_by`, as before.
            if ($rows->isNotEmpty()) {
                $now = now();

                // Tahap 6.9 R9.4-11 — one record per successful action, written in
                // this transaction so it exists only if the row updates commit too.
                $resolutionId = DB::table('import_room_mapping_resolutions')->insertGetId([
                    'import_batch_id' => $batch->id,
                    'location_code' => $locationCode,
                    'raw_value' => $rawValue,
                    'match_key' => $matchKey,
                    'room_id' => $roomId,
                    'method' => $method,
                    'room_alias_id' => $aliasId,
                    'alias_created' => $aliasCreated,
                    'affected_rows' => $rows->count(),
                    'resolved_by' => $actor->id,
                    'resolved_at' => $now,
                ]);

                foreach ($rows as $row) {
                    [$status, $messages] = $this->withoutRoomUnmapped($row);
                    if ($status === RowValidation::VALID && $row->validation_status !== RowValidation::VALID) {
                        $becameValid++;
                    }

                    $updated += DB::table('import_rows')
                        ->where('id', $row->id)
                        ->update([
                            'matched_room_id' => $roomId,
                            'room_match_method' => $method,
                            'room_mapping_resolution_id' => $resolutionId,
                            'validation_status' => $status,
                            'validation_messages' => $messages,
                            'updated_at' => $now,
                        ]);
                }

                // Keep the batch counters AssetPromoter maintains in step with the
                // rows just changed. Every candidate row was `warning` and is locked
                // above, so the delta is exact; `promotable` (valid + warning) and
                // therefore the batch status are unchanged.
                if ($becameValid > 0) {
                    DB::table('import_batches')->where('id', $batch->id)->update([
                        'valid_rows' => DB::raw('valid_rows + '.$becameValid),
                        'warning_rows' => DB::raw('warning_rows - '.$becameValid),
                        'updated_at' => $now,
                    ]);
                }

                $resolution = [
                    'id' => $resolutionId,
                    'resolved_by' => ['id' => $actor->id, 'name' => $actor->name],
                    'resolved_at' => $now->toIso8601String(),
                ];
            }

            return [
                'location_code' => $locationCode,
                'match_key' => $matchKey,
                'room' => ['id' => $room->id, 'name' => $room->name],
                'room_match_method' => $method,
                'updated_rows' => $updated,
                'rows_became_valid' => $becameValid,
                'alias_created' => $aliasCreated,
                'alias_already_existed' => $aliasAlreadyExisted,
                'resolution' => $resolution,
            ];
        });
    }

    /**
     * Tahap 6.9 R9.4-01 — the mapping resolves exactly one condition:
     * `room_unmapped`. That message is dropped; every other message stays
     * verbatim. The row becomes `valid` only when nothing else keeps it at
     * `warning` — any other warning (unknown condition, category mismatch, …) or
     * any validation error leaves the status as it was.
     *
     * `promotion_failed` is not a validation result: AssetPromoter appends it
     * (severity `error`) WITHOUT changing `validation_status`, so the row stays
     * retryable. It is kept as history and ignored here, so resolving never turns
     * a retryable row into an `error` row (never re-derived via
     * {@see RowValidation::fromMessages()}, which would).
     *
     * @return array{0:string, 1:string}  new validation_status, JSON-encoded messages (`[]` when none, as staging writes)
     */
    private function withoutRoomUnmapped(object $row): array
    {
        $messages = json_decode((string) $row->validation_messages, true) ?: [];
        $remaining = array_values(array_filter(
            $messages,
            fn (array $m): bool => ($m['code'] ?? null) !== 'room_unmapped',
        ));

        $stillFlagged = false;
        foreach ($remaining as $m) {
            $severity = $m['severity'] ?? null;
            if ($severity === 'warning' || ($severity === 'error' && ($m['code'] ?? null) !== 'promotion_failed')) {
                $stillFlagged = true;
                break;
            }
        }

        $status = $stillFlagged ? $row->validation_status : RowValidation::VALID;

        return [
            $status,
            json_encode($remaining, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }
}
