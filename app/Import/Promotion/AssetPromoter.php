<?php

namespace App\Import\Promotion;

use App\Import\Parsing\ParsedRow;
use App\Import\Validation\DuplicateChecker;
use App\Models\User;
use App\Policies\ImportBatchPolicy;
use App\Support\LocationScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Promotes staged rows into `assets` (Tahap 4 §26–§32).
 *
 *  - Only rows with validation_status IN ('valid','warning') AND promoted_asset_id IS NULL.
 *  - Each row promoted inside its own DB transaction. A row that fails on a constraint is
 *    NOT silently skipped — the error is written back to validation_messages.
 *  - Re-running is safe: an already-promoted row is skipped (idempotent).
 *  - `asset_code` is NEVER written — it is a DB generated column.
 *  - NO mutation_logs are created (initial inventory = initial state, §31).
 *  - Existing-asset duplicate is re-checked inside the transaction (final-state check, §29).
 *
 * Stage 6.9 R6 — `$actor` is authorized against every location this batch's
 * rows represent (since R9.4-08: ALL located rows, not only those still
 * awaiting promotion — see `assertBatchWithinScope()`), BEFORE the promotion
 * loop below touches a single row. This is deliberately independent of whatever check
 * `ImportManager::stageFile()` ran at staging time: the actor calling
 * `promoteBatch()` may be a different user entirely from whoever staged it
 * (a colleague, or the same unit_admin after a location reassignment), so
 * "was this batch created by an authorized actor" is not a substitute for
 * "is the CURRENT actor authorized for this data, right now". `$actor ===
 * null` (the CLI command's path) means unrestricted, matching a global role.
 */
final class AssetPromoter
{
    public function __construct(private readonly DuplicateChecker $duplicates)
    {
    }

    /**
     * @return array{promoted:int, skipped_already:int, failed:int, errors:list<array{row:int,message:string}>}
     */
    public function promoteBatch(int $batchId, ?User $actor = null): array
    {
        $batch = DB::table('import_batches')->find($batchId);
        if ($batch === null) {
            throw new RuntimeException("Import batch {$batchId} not found.");
        }

        // Tahap 6.9 R9.4-08 — the scope check runs BEFORE the status check, so a
        // scoped actor learns nothing about a batch it may not touch (not even its
        // status, which the 422 below would otherwise reveal).
        if ($actor !== null) {
            $this->assertBatchWithinScope($batchId, LocationScope::for($actor));
        }

        if (! in_array($batch->status, ['validated', 'partially_imported', 'imported'], true)) {
            throw new RuntimeException(
                "Batch {$batchId} is [{$batch->status}] — validate it before promotion."
            );
        }

        $result = ['promoted' => 0, 'skipped_already' => 0, 'failed' => 0, 'errors' => []];

        $rows = DB::table('import_rows')
            ->where('import_batch_id', $batchId)
            ->orderBy('row_number')
            ->get();

        foreach ($rows as $stagedRow) {
            if ($stagedRow->promoted_asset_id !== null) {
                $result['skipped_already']++;

                continue;
            }
            if (! in_array($stagedRow->validation_status, ['valid', 'warning'], true)) {
                continue;
            }

            try {
                $assetId = $this->promoteOne($stagedRow, $actor?->id);
                $result['promoted']++;
            } catch (Throwable $e) {
                $result['failed']++;
                $result['errors'][] = ['row' => (int) $stagedRow->row_number, 'message' => $e->getMessage()];
                $this->recordPromotionError($stagedRow, $e->getMessage());
            }
        }

        $this->refreshBatchCounters($batchId, $result['promoted'] > 0, $actor?->id);

        return $result;
    }

    /**
     * Tahap 6.9 R9.4-08 — a scoped actor (unit_admin) may promote a batch only
     * when EVERY row that resolved to a location — whatever its validation
     * status, promoted or not — is inside its scope, and at least one such row
     * exists. Rows with no location (unparseable) carry no location to check
     * and are ignored, same as staging.
     *
     * This is the same rule `ImportManager::rejectOutOfScopeRows()` already
     * applies at staging time. The previous
     * check looked only at rows still awaiting promotion, so once every row of
     * a foreign batch was promoted that set was empty and the check passed
     * vacuously — letting any unit_admin "promote" (and read back) another
     * unit's batch. Consequences, all deliberate:
     *   - single-location batch in scope            -> allowed (unchanged)
     *   - batch with any row outside scope (mixed,   -> 403, even if those rows
     *     or fully foreign)                             are errors or already promoted
     *   - batch with no located row at all           -> 403 (nothing ties it to the
     *                                                    actor's unit)
     * Global actors (and the CLI's `$actor === null`) never reach this method.
     * The batch-level `import_batches.location_code` is never used: it is NULL
     * for multi-location batches, so only the rows themselves are authoritative.
     *
     * Tahap 6.9 R9.4-07 (D2) — the rule itself now lives in
     * {@see ImportBatchPolicy::batchWithinScope()} (unchanged), so viewing,
     * room mapping and promotion all share one boundary. Promotion keeps its
     * 403 here.
     *
     * @throws AuthorizationException
     */
    private function assertBatchWithinScope(int $batchId, LocationScope $scope): void
    {
        if (! ImportBatchPolicy::batchWithinScope($batchId, $scope)) {
            throw new AuthorizationException(
                "Batch {$batchId} contains data outside your assigned location."
            );
        }
    }

    private function promoteOne(object $stagedRow, ?int $actorId): int
    {
        $payload = json_decode((string) $stagedRow->raw_payload, true, flags: JSON_THROW_ON_ERROR);
        $parsed = $payload['parsed'] ?? null;
        if (! is_array($parsed)) {
            throw new RuntimeException('raw_payload has no "parsed" section');
        }

        return DB::transaction(function () use ($stagedRow, $parsed, $actorId): int {
            // final-state duplicate guard (§29) — another batch may have promoted the same
            // identity since this row was validated.
            $identity = new ParsedRow(
                rowNumber: (int) $stagedRow->row_number,
                locationCode: $parsed['location_code'] ?? null,
                categoryCode: $parsed['category_code'] ?? null,
                subcategoryCode: $parsed['subcategory_code'] ?? null,
                sequenceNo: $parsed['sequence_no'] ?? null,
                assetYear: $parsed['asset_year'] ?? null,
                roomRawValue: null, conditionRaw: '', conditionParsed: null,
                isWrittenOff: false, writtenOffOn: null, writtenOffNote: null,
                brandModel: null, serialNo: null, material: null, purchaseDate: null,
                fundingSource: null, detailType: null, capacityNote: null,
                quantity: 1, notes: null, blockSubcategoryCode: null,
            );
            $existing = $this->duplicates->existingAssetId($identity);
            if ($existing !== null) {
                throw new RuntimeException("identity already exists as asset id {$existing}");
            }

            // Tahap 6.9 R9.4-19 — final-state room guard, same idea as the duplicate
            // guard above: the room matched at staging (or resolved later) may have
            // been deactivated since. Never silently assign an asset to an inactive
            // room, and never auto-reactivate or substitute one — the row fails
            // like any other promotion failure (error recorded on the row, row stays
            // unpromoted) and a later promote succeeds once the room is active
            // again. Shared lock: the room can't be deactivated between this check
            // and the insert below. Cross-location rooms remain rejected by the
            // composite FK (location_code, room_id) -> rooms, exactly as before.
            if ($stagedRow->matched_room_id !== null) {
                $room = DB::table('rooms')->where('id', $stagedRow->matched_room_id)->sharedLock()->first(['id', 'is_active']);
                if ($room === null || ! $room->is_active) {
                    throw new RuntimeException(
                        "matched room id {$stagedRow->matched_room_id} is no longer active — reactivate it, then promote again"
                    );
                }
            }

            $now = now();
            $assetId = DB::table('assets')->insertGetId([
                'location_code' => $parsed['location_code'],
                'category_code' => $parsed['category_code'],
                'subcategory_code' => $parsed['subcategory_code'],
                'sequence_no' => $parsed['sequence_no'],       // string, EXACTLY as read
                'asset_year' => $parsed['asset_year'],
                // asset_code: GENERATED — do NOT insert
                'room_id' => $stagedRow->matched_room_id,       // null => unmapped (kept for review)
                'room_raw_value' => $parsed['room_raw_value'],
                'condition' => $parsed['condition_parsed'],     // may be null (unknown/ambiguous)
                'is_written_off' => $parsed['is_written_off'] ? 1 : 0,
                'written_off_on' => $parsed['written_off_on'],
                'written_off_note' => $parsed['written_off_note'],
                'brand_model' => $parsed['brand_model'],
                'serial_no' => $parsed['serial_no'],
                'material' => $parsed['material'],
                'purchase_date' => $parsed['purchase_date'],
                'funding_source' => $parsed['funding_source'],
                'detail_type' => $parsed['detail_type'],
                'capacity_note' => $parsed['capacity_note'],
                'quantity' => 1,
                'notes' => $parsed['notes'],
                'import_row_id' => $stagedRow->id,
                'created_by' => null,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Tahap 6.9 R9.4-11 — `promoted_by` is written by the same UPDATE that
            // claims the row, inside this transaction: a row whose promotion fails
            // never gets an actor. NULL for the CLI (no user).
            $affected = DB::table('import_rows')
                ->where('id', $stagedRow->id)
                ->whereNull('promoted_asset_id')
                ->update(['promoted_asset_id' => $assetId, 'promoted_at' => $now, 'promoted_by' => $actorId, 'updated_at' => $now]);

            if ($affected !== 1) {
                // concurrent promotion of the same row — abort so we don't double count
                throw new RuntimeException('import_row was promoted concurrently');
            }

            return $assetId;
        });
    }

    private function recordPromotionError(object $stagedRow, string $message): void
    {
        $messages = json_decode((string) $stagedRow->validation_messages, true) ?: [];
        $messages[] = [
            'code' => 'promotion_failed',
            'severity' => 'error',
            'field' => null,
            'message' => 'promotion failed: ' . $message,
        ];
        DB::table('import_rows')->where('id', $stagedRow->id)->update([
            'validation_messages' => json_encode($messages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }

    private function refreshBatchCounters(int $batchId, bool $promotedAny, ?int $actorId): void
    {
        $base = DB::table('import_rows')->where('import_batch_id', $batchId);

        $total = (clone $base)->count();
        $valid = (clone $base)->where('validation_status', 'valid')->count();
        $warning = (clone $base)->where('validation_status', 'warning')->count();
        $error = (clone $base)->where('validation_status', 'error')->count();
        $imported = (clone $base)->whereNotNull('promoted_asset_id')->count();
        $promotable = $valid + $warning;

        $status = match (true) {
            $total > 0 && $error === $total => 'failed',
            $promotable > 0 && $imported === 0 => 'validated',
            $imported > 0 && $imported < $promotable => 'partially_imported',
            $imported > 0 && $imported === $promotable => 'imported',
            default => 'validated',
        };

        $now = now();

        DB::table('import_batches')->where('id', $batchId)->update([
            'status' => $status,
            'total_rows' => $total,
            'valid_rows' => $valid,
            'warning_rows' => $warning,
            'error_rows' => $error,
            'imported_rows' => $imported,
            'updated_at' => $now,
        ]);

        // Tahap 6.9 R9.4-09 — `imported_at` is WHEN the batch first had an asset
        // imported, so it is written exactly once: only while still NULL, and only
        // once something is actually imported. A re-promote (idempotent or one
        // that adds more rows later) never moves it; a promote that imports
        // nothing never sets it. Previously it was rewritten to now() on every
        // call with imported > 0. The `whereNull` makes the write-once rule hold
        // even under concurrent promotes.
        //
        // Tahap 6.9 R9.4-11 — `imported_by` is the actor of that same first
        // promotion, written by the same guarded UPDATE so the pair can never
        // disagree or be replaced later. Only a call that itself promoted a row
        // may claim it (`$promotedAny`), so a no-op/all-failed promote racing a
        // successful one never takes the credit. NULL actor = CLI.
        if ($imported > 0 && $promotedAny) {
            DB::table('import_batches')->where('id', $batchId)->whereNull('imported_at')->update([
                'imported_at' => $now,
                'imported_by' => $actorId,
            ]);
        }
    }
}
