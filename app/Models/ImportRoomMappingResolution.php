<?php

namespace App\Models;

use App\Import\RoomMapping\RoomMappingResolver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tahap 6.9 R9.4-11 — one successful import room-mapping action: which group
 * (batch + location + match_key) was resolved to which room, how
 * (`manual` / `manual_alias`), by whom and when. Written only by
 * {@see RoomMappingResolver}, in the same transaction as
 * the staged-row updates; never updated afterwards.
 *
 * No `created_at`/`updated_at`: the record is immutable and `resolved_at` is its
 * only meaningful time.
 */
#[Fillable([
    'import_batch_id',
    'location_code',
    'raw_value',
    'match_key',
    'room_id',
    'method',
    'room_alias_id',
    'alias_created',
    'affected_rows',
    'resolved_by',
    'resolved_at',
])]
class ImportRoomMappingResolution extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'alias_created' => 'boolean',
            'affected_rows' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ImportBatch, $this> */
    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    /** @return BelongsTo<RoomAlias, $this> */
    public function roomAlias(): BelongsTo
    {
        return $this->belongsTo(RoomAlias::class, 'room_alias_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** The staged rows this action mapped. @return HasMany<ImportRow, $this> */
    public function importRows(): HasMany
    {
        return $this->hasMany(ImportRow::class, 'room_mapping_resolution_id');
    }
}
