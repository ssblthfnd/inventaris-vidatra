<?php

namespace App\Import\Validation;

use App\Import\Matching\RoomMatchResult;
use App\Import\Parsing\ParsedRow;

/**
 * Applies the validation rules from schema_design.md §7.3 / Tahap 4 §25 to a parsed row.
 *
 * ERROR   -> not safe to become an asset (invalid loc/cat/subcat, empty sequence,
 *            invalid year, quantity != 1, malformed identity, duplicate identity).
 * WARNING -> promotable but needs attention (room unmapped, unknown/ambiguous condition,
 *            suspicious optional field, block mismatch, category mismatch).
 * VALID   -> no error, no warning.
 *
 * Parse notes produced upstream by RowParser are merged in.
 */
final class RowValidator
{
    public function __construct(
        private readonly MasterData $master,
        private readonly DuplicateChecker $duplicates,
    ) {
    }

    public function validate(ParsedRow $row, RoomMatchResult $room, ?string $batchCategoryCode): RowValidation
    {
        /** @var list<array{code:string,severity:string,field:?string,message:string}> $msgs */
        $msgs = $row->parseNotes;
        $duplicateOfAssetId = null;

        // --- identity: location -------------------------------------------------------
        if ($row->locationCode === null) {
            $msgs[] = $this->m('location_missing', 'error', 'location_code', 'no location code in column B');
        } elseif (! $this->master->hasLocation($row->locationCode)) {
            $msgs[] = $this->m('invalid_location', 'error', 'location_code',
                "location [{$row->locationCode}] is not in the locations master");
        }

        // --- identity: category ------------------------------------------------------
        if ($row->categoryCode === null) {
            $msgs[] = $this->m('category_missing', 'error', 'category_code', 'no category code in column C');
        } elseif (! $this->master->hasCategory($row->categoryCode)) {
            $msgs[] = $this->m('invalid_category', 'error', 'category_code',
                "category [{$row->categoryCode}] is not in the categories master");
        } elseif ($batchCategoryCode !== null && $row->categoryCode !== $batchCategoryCode) {
            // "1 batch = 1 category" enforced at validation, not as a NOT NULL column (F10)
            $msgs[] = $this->m('category_mismatch', 'warning', 'category_code',
                "row category [{$row->categoryCode}] differs from batch category [{$batchCategoryCode}]");
        }

        // --- identity: subcategory (composite with category) -------------------------
        if ($row->subcategoryCode === null) {
            $msgs[] = $this->m('subcategory_missing', 'error', 'subcategory_code', 'no subcategory code (column D / block header)');
        } elseif ($row->categoryCode !== null
            && $this->master->hasCategory($row->categoryCode)
            && ! $this->master->hasSubcategoryPair($row->categoryCode, $row->subcategoryCode)) {
            $msgs[] = $this->m('invalid_subcategory', 'error', 'subcategory_code',
                "pair ({$row->categoryCode}, {$row->subcategoryCode}) is not in the subcategories master");
        }

        // --- identity: sequence_no --------------------------------------------------
        if ($row->sequenceNo === null || trim($row->sequenceNo) === '') {
            $msgs[] = $this->m('missing_sequence', 'error', 'sequence_no', 'sequence_no (column E) is empty');
        } elseif (mb_strlen($row->sequenceNo) > 10) {
            $msgs[] = $this->m('sequence_too_long', 'error', 'sequence_no',
                "sequence_no [{$row->sequenceNo}] exceeds 10 characters");
        }
        // NOTE: an alphabetic suffix ("005A") is explicitly VALID — no message (§22).

        // --- identity: asset_year --------------------------------------------------
        if ($row->assetYear === null) {
            // asset_year_missing / _unparseable already added by RowParser as error
            if (! $this->hasCode($msgs, 'asset_year_missing') && ! $this->hasCode($msgs, 'asset_year_unparseable')) {
                $msgs[] = $this->m('invalid_year', 'error', 'asset_year', 'asset_year could not be determined');
            }
        } elseif ($row->assetYear < 1980 || $row->assetYear > 2100) {
            $msgs[] = $this->m('invalid_year', 'error', 'asset_year',
                "asset_year [{$row->assetYear}] outside allowed range 1980..2100");
        }

        // --- quantity: must be exactly 1 (§14/§22, DB CHECK) -----------------------
        if ($row->quantity !== 1) {
            $msgs[] = $this->m('quantity_not_one', 'error', 'quantity',
                "quantity [{$row->quantity}] — grouped assets are not supported (1 row = 1 unit)");
        }

        // --- room: location-aware match ------------------------------------------------
        if ($row->roomRawValue === null || trim($row->roomRawValue) === '') {
            $msgs[] = $this->m('room_missing', 'warning', 'room_id', 'no room value in column O');
        } elseif ($room->method === RoomMatchResult::METHOD_NONE) {
            $msgs[] = $this->m('room_unmapped', 'warning', 'room_id',
                "room [{$room->rawValue}] not matched for location [{$row->locationCode}] (key [{$room->matchKey}])");
        }

        // --- duplicate: within this batch (message only, no asset id) ---------------
        $firstBatchRow = $this->duplicates->firstBatchRowFor($row);
        if ($firstBatchRow !== null) {
            $msgs[] = $this->m('duplicate_in_batch', 'error', null,
                "business identity [{$row->identityLabel()}] duplicates row {$firstBatchRow} in this batch");
        }

        // --- duplicate: against existing assets ------------------------------------
        $existingId = $this->duplicates->existingAssetId($row);
        if ($existingId !== null) {
            $duplicateOfAssetId = $existingId;
            $msgs[] = $this->m('duplicate_existing_asset', 'error', null,
                "business identity [{$row->identityLabel()}] already exists as asset id {$existingId}");
        }

        // record this row's identity for later rows in the same batch
        $this->duplicates->remember($row);

        return RowValidation::fromMessages($msgs, $duplicateOfAssetId);
    }

    /** @param list<array{code:string,severity:string,field:?string,message:string}> $msgs */
    private function hasCode(array $msgs, string $code): bool
    {
        foreach ($msgs as $m) {
            if ($m['code'] === $code) {
                return true;
            }
        }

        return false;
    }

    /** @return array{code:string,severity:string,field:?string,message:string} */
    private function m(string $code, string $severity, ?string $field, string $message): array
    {
        return ['code' => $code, 'severity' => $severity, 'field' => $field, 'message' => $message];
    }
}
