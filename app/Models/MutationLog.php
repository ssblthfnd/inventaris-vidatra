<?php

namespace App\Models;

use App\Enums\AssetCondition;
use Database\Factories\MutationLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only history of asset changes (schema_design.md §2.7).
 *
 * The table has NO `updated_at` column — `const UPDATED_AT = null` stops Eloquent from
 * touching it. `created_at` defaults to `CURRENT_TIMESTAMP` in the DB.
 *
 * Rows are NEVER updated or deleted here — corrections are new rows. No update/delete
 * helpers are provided. The mutation-writing service arrives in Tahap 5.5.
 *
 * The from/to room FKs are composite `(*_location_code, *_room_id) -> rooms(location_code, id)`;
 * the `fromRoom()` / `toRoom()` relations use the `*_room_id -> rooms.id` key for reads.
 */
#[Fillable([
    'asset_id',
    'type',
    'mutation_date',
    'from_location_code',
    'to_location_code',
    'from_room_id',
    'to_room_id',
    'from_room_label',
    'to_room_label',
    'condition_before',
    'condition_after',
    'notes',
    'performed_by',
])]
class MutationLog extends Model
{
    /** @use HasFactory<MutationLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'mutation_date' => 'date',
            'condition_before' => AssetCondition::class,
            'condition_after' => AssetCondition::class,
        ];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /** @return BelongsTo<Location, $this> */
    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_code', 'code');
    }

    /** @return BelongsTo<Location, $this> */
    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_code', 'code');
    }

    /** @return BelongsTo<Room, $this> */
    public function fromRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'from_room_id');
    }

    /** @return BelongsTo<Room, $this> */
    public function toRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'to_room_id');
    }
}
