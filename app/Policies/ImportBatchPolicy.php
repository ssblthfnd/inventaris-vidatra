<?php

namespace App\Policies;

use App\Models\ImportBatch;
use App\Models\User;
use App\Support\LocationScope;

/**
 * Stage 6.9 R6 — single-resource authorization for one `ImportBatch`,
 * matching the same Policy pattern {@see AssetPolicy} established in R4/R5.
 *
 * A batch has no single `location_code` column (schema_design.md — one file
 * can legitimately span multiple locations for a global actor), so "is this
 * batch in my scope" can't reuse `LocationScope::allows()` against a single
 * column the way `AssetPolicy` does. For `unit_admin`, R6 uses OWNERSHIP
 * instead: they may view a batch only if THEY uploaded it — never another
 * user's import, even one that happens to be for the same location. This is
 * deliberately the simplest rule that satisfies "no cross-unit import-data
 * leakage" without inventing a batch-wide location-matching scheme the
 * approved design didn't ask for; `ImportManager`/`AssetPromoter` are what
 * actually enforce location scope, on staging and promotion respectively —
 * this Policy only controls who may look.
 */
class ImportBatchPolicy
{
    public function view(User $user, ImportBatch $batch): bool
    {
        if (LocationScope::for($user)->isGlobal()) {
            return true;
        }

        return $batch->uploaded_by === $user->id;
    }
}
