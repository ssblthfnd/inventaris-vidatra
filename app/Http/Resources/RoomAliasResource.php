<?php

namespace App\Http\Resources;

use App\Models\RoomAlias;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read RoomAlias $resource
 *
 * `room.is_active` is included deliberately (unlike `RoomResource`, which
 * never exposes it to non-admin readers) — the admin/operator management UI
 * needs to visually flag an alias that points to a now-inactive room (Tahap
 * 6.8.2). This is intentional, not an oversight: an alias may point to an
 * inactive room (see `RoomAliasController`'s docblock).
 */
class RoomAliasResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $alias = $this->resource;

        return [
            'id' => $alias->id,
            'location' => [
                'code' => $alias->location_code,
                'name' => $alias->location?->name,
            ],
            'raw_value' => $alias->raw_value,
            'match_key' => $alias->match_key,
            'room' => [
                'id' => $alias->room_id,
                'name' => $alias->room?->name,
                'is_active' => $alias->room?->is_active,
            ],
            'source' => $alias->source,
            'notes' => $alias->notes,
            'created_by' => $alias->created_by === null ? null : [
                'id' => $alias->created_by,
                'name' => $alias->createdBy?->name,
            ],
            'created_at' => $alias->created_at?->toISOString(),
        ];
    }
}
