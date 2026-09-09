<?php

namespace App\Http\Resources;

use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Room $resource
 *
 * Room aliases are NOT exposed here — they get their own endpoints in the
 * write-management stage.
 */
class RoomResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $room = $this->resource;

        return [
            'id' => $room->id,
            'name' => $room->name,
            'location' => [
                'code' => $room->location_code,
                'name' => $room->location?->name,
            ],
            'pic' => $room->pic,
            'notes' => $room->notes,
            'is_active' => $room->is_active,
        ];
    }
}
