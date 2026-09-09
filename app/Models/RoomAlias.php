<?php

namespace App\Models;

use Database\Factories\RoomAliasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kamus alias ruangan — "raw string Excel -> rooms.id", scoped per lokasi
 * (schema_design.md §2.5).
 *
 * The database enforces `(location_code, room_id) -> rooms(location_code, id)` (a
 * composite FK) so an alias can never point at a room in another location. The
 * `room()` relation below uses the standalone `room_id -> rooms.id` FK (which also
 * exists) — correct for reads; the location match is guaranteed by the DB and honoured
 * by the service layer (Tahap 5.x).
 *
 * `source` is left as a plain string cast — the DB ENUM('tahap1_seed','manual')
 * already constrains the value; no extra PHP enum is warranted.
 */
#[Fillable(['location_code', 'raw_value', 'match_key', 'room_id', 'source', 'notes', 'created_by'])]
class RoomAlias extends Model
{
    /** @use HasFactory<RoomAliasFactory> */
    use HasFactory;

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_code', 'code');
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
