<?php

namespace App\Models;

use App\Enums\AssetCondition;
use Database\Factories\ImportRowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One staged Excel row (schema_design.md §2.10).
 *
 *  - `raw_payload` (NN) and `validation_messages` (nullable) are JSON -> cast to array.
 *  - `sequence_no` stays a string verbatim (not cast).
 *  - `duplicate_of_asset_id` (the row FAILED — clashes with an existing asset) and
 *    `promoted_asset_id` (the row SUCCEEDED — this is the asset made from it) are two
 *    distinct references and are kept separate.
 */
#[Fillable([
    'import_batch_id',
    'row_number',
    'raw_payload',
    'location_code',
    'category_code',
    'subcategory_code',
    'sequence_no',
    'asset_year',
    'room_raw_value',
    'matched_room_id',
    'room_match_method',
    'condition_raw',
    'condition_parsed',
    'validation_status',
    'validation_messages',
    'duplicate_of_asset_id',
    'promoted_asset_id',
    'promoted_at',
])]
class ImportRow extends Model
{
    /** @use HasFactory<ImportRowFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'validation_messages' => 'array',
            'row_number' => 'integer',
            'asset_year' => 'integer',
            'condition_parsed' => AssetCondition::class,
            'promoted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ImportBatch, $this> */
    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    /** @return BelongsTo<Room, $this> */
    public function matchedRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'matched_room_id');
    }

    /** The asset successfully created from this row. @return BelongsTo<Asset, $this> */
    public function promotedAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'promoted_asset_id');
    }

    /** The existing asset this row duplicates (row rejected). @return BelongsTo<Asset, $this> */
    public function duplicateOfAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'duplicate_of_asset_id');
    }
}
