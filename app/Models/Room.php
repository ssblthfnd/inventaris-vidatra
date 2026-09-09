<?php

namespace App\Models;

use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master ruangan — application-managed, per lokasi (schema_design.md §2.4).
 * Lifecycle via `is_active` (soft-disable), NOT SoftDeletes — a disabled room must
 * stay joinable for historical assets and mutation logs (§11.1).
 *
 * There is NO hard-coded list of rooms anywhere — seeding lives in RoomSeeder.
 */
#[Fillable(['location_code', 'name', 'pic', 'notes', 'is_active'])]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_code', 'code');
    }

    /** @return HasMany<RoomAlias, $this> */
    public function roomAliases(): HasMany
    {
        return $this->hasMany(RoomAlias::class, 'room_id');
    }

    /** @return HasMany<Asset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'room_id');
    }
}
