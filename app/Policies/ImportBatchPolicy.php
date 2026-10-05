<?php

namespace App\Policies;

use App\Import\Promotion\AssetPromoter;
use App\Import\RoomMapping\RoomMappingResolver;
use App\Models\ImportBatch;
use App\Models\User;
use App\Support\LocationScope;
use Illuminate\Support\Facades\DB;

/**
 * Stage 6.9 R6 — single-resource authorization for one `ImportBatch`,
 * matching the same Policy pattern {@see AssetPolicy} established in R4/R5.
 *
 * Tahap 6.9 R9.4-07 (D2) — LOCATION scope is the boundary for every batch
 * operation; the uploader (`uploaded_by`) is audit metadata only. R6 used
 * ownership here for viewing while mapping/promotion were already
 * location-based, so a colocated unit_admin could promote a colleague's batch
 * without being able to look at it first. {@see batchWithinScope()} is now the
 * ONE rule behind viewing (show / rows / report / room-mappings), room-mapping
 * resolution ({@see RoomMappingResolver::resolve()})
 * and promotion ({@see AssetPromoter}).
 *
 * A batch has no single `location_code` column (one file can span several
 * locations for a global actor), so its location is what its ROWS say.
 */
class ImportBatchPolicy
{
    public function view(User $user, ImportBatch $batch): bool
    {
        return self::batchWithinScope($batch->id, LocationScope::for($user));
    }

    /**
     * Global actors may reach every batch. A scoped actor (unit_admin) may reach
     * a batch only when EVERY row that resolved to a location — whatever its
     * validation status, promoted or not — is inside its scope, and at least one
     * such row exists:
     *   - single-location batch in scope           -> allowed
     *   - any located row outside scope (mixed or  -> denied, even if those rows
     *     fully foreign)                               are errors or promoted
     *   - no located row at all                    -> denied (nothing ties the
     *                                                  batch to the actor's unit)
     * Rows without a location (unparseable) carry nothing to check and are
     * ignored. `uploaded_by` never matters. This is the rule R9.4-08 introduced
     * for promotion (same as staging's `ImportManager::rejectOutOfScopeRows()`),
     * moved here unchanged so every batch operation shares it.
     */
    public static function batchWithinScope(int $batchId, LocationScope $scope): bool
    {
        if ($scope->isGlobal()) {
            return true;
        }

        $locationCodes = DB::table('import_rows')
            ->where('import_batch_id', $batchId)
            ->whereNotNull('location_code')
            ->distinct()
            ->pluck('location_code');

        return $locationCodes->isNotEmpty()
            && ! $locationCodes->contains(fn (string $code): bool => ! $scope->allows($code));
    }
}
