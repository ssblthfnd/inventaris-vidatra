<?php

namespace App\Models;

use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master lokasi (`01` YAYASAN/PH, `02` SD, `03` SMP, `04` SMA).
 * Natural string primary key `code` — data/reference/schema_design.md §2.1, §13.
 */
#[Fillable(['code', 'name', 'alias', 'is_active'])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Room, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class, 'location_code', 'code');
    }

    /** @return HasMany<RoomAlias, $this> */
    public function roomAliases(): HasMany
    {
        return $this->hasMany(RoomAlias::class, 'location_code', 'code');
    }

    /** @return HasMany<Asset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'location_code', 'code');
    }
}
