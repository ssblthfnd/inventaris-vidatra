<?php

namespace App\Import\Validation;

use App\Import\Parsing\ParsedRow;
use Illuminate\Support\Facades\DB;

/**
 * Duplicate detection against business identity
 *   (location_code, category_code, subcategory_code, sequence_no, asset_year)
 *
 *  - within the current batch: tracked in memory; reported via validation messages only
 *    (NOT via duplicate_of_asset_id — there is no asset id yet). Tahap 4 §23.
 *  - against existing `assets`: returns the existing asset id for duplicate_of_asset_id.
 *    Soft-deleted assets count (the number is not reusable). Tahap 4 §24.
 */
final class DuplicateChecker
{
    /** @var array<string,int>  identityKey => first row_number seen in this batch */
    private array $seenInBatch = [];

    public function firstBatchRowFor(ParsedRow $row): ?int
    {
        return $this->seenInBatch[$row->identityKey()] ?? null;
    }

    public function remember(ParsedRow $row): void
    {
        $this->seenInBatch[$row->identityKey()] ??= $row->rowNumber;
    }

    public function reset(): void
    {
        $this->seenInBatch = [];
    }

    /** @return int|null  existing asset id, or null */
    public function existingAssetId(ParsedRow $row): ?int
    {
        if ($row->locationCode === null || $row->categoryCode === null
            || $row->subcategoryCode === null || $row->sequenceNo === null || $row->assetYear === null) {
            return null;
        }

        return $this->existingAssetIdFor(
            $row->locationCode,
            $row->categoryCode,
            $row->subcategoryCode,
            $row->sequenceNo,
            $row->assetYear,
        );
    }

    /**
     * The identity lookup itself, on plain values — {@see existingAssetId()} for a
     * staged import row, and (Tahap 6.9 R10) the asset multi-entry create for a
     * manually entered number. The comparison is done by MySQL, so it uses the
     * same collation as `uq_assets_number`.
     */
    public function existingAssetIdFor(
        string $locationCode,
        string $categoryCode,
        string $subcategoryCode,
        string $sequenceNo,
        int $assetYear,
    ): ?int {
        // Plain query builder: sees ALL rows including soft-deleted (deleted_at not null),
        // so a soft-deleted asset still blocks its number from being reused.
        $id = DB::table('assets')
            ->where('location_code', $locationCode)
            ->where('category_code', $categoryCode)
            ->where('subcategory_code', $subcategoryCode)
            ->where('sequence_no', $sequenceNo)
            ->where('asset_year', $assetYear)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }
}
