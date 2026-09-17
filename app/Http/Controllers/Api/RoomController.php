<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreRoomRequest;
use App\Http\Requests\Api\UpdateRoomRequest;
use App\Http\Resources\RoomResource;
use App\Models\Location;
use App\Models\Room;
use App\Policies\RoomPolicy;
use App\Support\LocationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Master data: rooms.
 *
 * `index()`/`show()` (Tahap 5.3) are read-only, active-only. Every existing
 * global-role (viewer/operator/admin/super_admin) behaviour on
 * `GET /api/locations/{location}/rooms` and `GET /api/rooms/{room}` stays
 * exactly as it was (see `tests/Feature/Api/MasterDataReadApiTest.php`).
 * Stage 6.9 R4 adds one thing on top: a `unit_admin` requesting a location/
 * room outside their own assigned location gets the SAME 404 an inactive
 * location/room already gets — never a 403, so a cross-unit room's
 * existence is never confirmed. This is READ scope only; `unit_admin` gains
 * no create/update/delete ability here (that's a later phase).
 *
 * `adminIndex()` (Tahap 6.8.1, `can:admin`) is a new, separate endpoint for
 * the Master Data management UI — deliberately a different route
 * (`GET /api/rooms`, flat, all locations, active AND inactive) rather than
 * adding an "include inactive" mode to the existing `index()`/`show()`, so
 * their well-tested read contract is never at risk of regressing.
 * `unit_admin` never reaches it (`can:admin` gate) — see this class's R7 note
 * on `store()`/`update()` for why that stays admin-only even though room
 * management itself is no longer admin-only.
 *
 * `store()`/`update()` (Tahap 6.8.1, `can:admin`; Stage 6.9 R7 regated to
 * `can:rooms.manage` so `unit_admin` can reach them too, location-scoped via
 * {@see RoomPolicy}). The route gate only answers WHAT (does
 * this role have the ability at all); WHERE (is this room's location in the
 * actor's scope) is checked here, before `Room::create()`/`update()` ever
 * runs — same split as `AssetController`.
 *
 * Room aliases are still not exposed here — that is Tahap 6.8.2, and stays
 * out of scope for R7 too (`roomAliases.manage` is deliberately excluded
 * from `unit_admin`, see `RoomAliasController`'s docblock).
 */
class RoomController extends ApiController
{
    public function index(Request $request, Location $location): AnonymousResourceCollection
    {
        abort_if(! $location->is_active, 404);
        abort_if(! LocationScope::for($request->user())->allows($location->code), 404);

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

    public function show(Request $request, Room $room): RoomResource
    {
        abort_if(! $room->is_active, 404);
        abort_if(! LocationScope::for($request->user())->allows($room->location_code), 404);

        $room->load('location');

        return new RoomResource($room);
    }

    /**
     * `GET /api/rooms` (Tahap 6.8.1), `can:admin` — every room across every
     * location, active AND inactive, for the Master Data management list.
     * Unpaginated like every other master-data collection (docs/api_convention.md):
     * even with SD/SMP/SMA populated this stays a small, finite list.
     */
    public function adminIndex(Request $request): AnonymousResourceCollection
    {
        $rooms = Room::query()
            ->with('location')
            ->when($request->filled('location_code'), function ($query) use ($request) {
                $query->where('location_code', $request->string('location_code'));
            })
            ->when($request->filled('q'), function ($query) use ($request) {
                $like = '%'.$this->escapeLike((string) $request->string('q')).'%';
                $query->where('name', 'like', $like);
            })
            ->orderBy('location_code')
            ->orderBy('name')
            ->get();

        return RoomResource::collection($rooms);
    }

    public function store(StoreRoomRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Stage 6.9 R7 — no existing Room to check yet; authorize the TARGET
        // location directly, same pattern as AssetController::store(). Never
        // rewritten to the actor's own location — an out-of-scope request is
        // rejected, not silently corrected.
        if ($request->user()->cannot('create', [Room::class, $data['location_code']])) {
            abort(403);
        }

        $room = Room::create([
            ...$data,
            'is_active' => true,
        ]);
        $room->load('location');

        return (new RoomResource($room))->response()->setStatusCode(201);
    }

    public function update(UpdateRoomRequest $request, Room $room): RoomResource
    {
        if ($request->user()->cannot('update', $room)) {
            abort(403);
        }

        $room->update($request->validated());
        $room->load('location');

        return new RoomResource($room);
    }
}
