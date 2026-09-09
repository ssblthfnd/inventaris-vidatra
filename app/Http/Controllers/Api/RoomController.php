<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\RoomResource;
use App\Models\Location;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only master data: rooms.
 *
 * The list endpoint is scoped to one location: `GET /api/locations/{location}/rooms`.
 * Room aliases are not exposed here (write-management stage).
 */
class RoomController extends ApiController
{
    public function index(Request $request, Location $location): AnonymousResourceCollection
    {
        abort_if(! $location->is_active, 404);

        $rooms = $location->rooms()
            ->where('is_active', true)
            ->when($request->filled('q'), function ($query) use ($request) {
                $like = '%'.$this->escapeLike((string) $request->string('q')).'%';
                $query->where('name', 'like', $like);
            })
            ->orderBy('name')
            ->get()
            ->each->setRelation('location', $location);

        return RoomResource::collection($rooms);
    }

    public function show(Room $room): RoomResource
    {
        abort_if(! $room->is_active, 404);

        $room->load('location');

        return new RoomResource($room);
    }
}
