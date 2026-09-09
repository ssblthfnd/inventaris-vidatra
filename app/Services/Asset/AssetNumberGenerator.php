<?php

namespace App\Services\Asset;

use Illuminate\Support\Facades\DB;

/**
 * Allocates the next `sequence_no` for a new asset (Tahap 5.4 §4–§6, §28).
 *
 * Scope of a sequence family is **location_code + category_code + subcategory_code** —
 * NOT the year (§4). Numbering is continuous across years.
 *
 *  - The "family" of an existing sequence is its leading numeric run:
 *    `005`, `005A`, `005B` all belong to family 5; `0017B` → 17; `0001` → 1.
 *    Comparison is numeric (via MySQL `REGEXP_SUBSTR` + `CAST … UNSIGNED`), never
 *    lexical — otherwise `'1000' < '999'`.
 *  - Soft-deleted rows are counted (§13): a plain query builder sees `deleted_at`
 *    rows, so a retired number is never handed out again.
 *  - New numbers are zero-padded to 3 digits up to `999`, then plain (`1000`, `1001`).
 *    Letter suffixes are historical-only and never generated (§6).
 *
 * Concurrency (§12): callers run inside a transaction and first call
 * {@see self::lockScope()} to take a row lock on the owning subcategory, which
 * serialises concurrent allocations for that (category, subcategory). The
 * `uq_assets_number` unique index is the final defence; {@see AssetWriteService}
 * retries on a duplicate race.
 */
class AssetNumberGenerator
{
    /**
     * Take a `FOR UPDATE` row lock on the owning subcategory so that concurrent
     * asset creations in the same (category, subcategory) are serialised. Must be
     * called inside the same transaction as the subsequent insert.
     */
    public function lockScope(string $categoryCode, string $subcategoryCode): void
    {
        DB::table('subcategories')
            ->where('category_code', $categoryCode)
            ->where('code', $subcategoryCode)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Next free sequence string for the given family scope.
     */
    public function next(string $locationCode, string $categoryCode, string $subcategoryCode): string
    {
        $row = DB::table('assets')
            ->where('location_code', $locationCode)
            ->where('category_code', $categoryCode)
            ->where('subcategory_code', $subcategoryCode)
            ->lockForUpdate()
            ->selectRaw("MAX(CAST(REGEXP_SUBSTR(sequence_no, '^[0-9]+') AS UNSIGNED)) AS max_family")
            ->first();

        $next = (int) ($row->max_family ?? 0) + 1;

        return $next <= 999
            ? str_pad((string) $next, 3, '0', STR_PAD_LEFT)
            : (string) $next;
    }
}
