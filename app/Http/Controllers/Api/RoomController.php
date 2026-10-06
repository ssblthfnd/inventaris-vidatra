<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreRoomEntriesRequest;
use App\Http\Requests\Api\StoreRoomRequest;
use App\Http\Requests\Api\UpdateRoomRequest;
use App\Http\Resources\RoomResource;
use App\Models\Location;
use App\Models\Room;
use App\Policies\RoomPolicy;
use App\Support\LocationScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

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
 * `adminIndex()` (Tahap 6.8.1) is a new, separate endpoint for
 * the Master Data management UI — deliberately a different route
 * (`GET /api/rooms`, flat, all locations, active AND inactive) rather than
 * adding an "include inactive" mode to the existing `index()`/`show()`, so
 * their well-tested read contract is never at risk of regressing.
 * Stage 6.9 R9.3 moved it from `can:admin` to `can:rooms.manage` (so
 * super_admin reaches it) plus a global-scope check inside: `unit_admin`
 * still never reaches it, since this list is not location-scoped and would
 * leak other units' rooms.
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
 *
 * `index()` gained one opt-in addition (R7.1): `?include_inactive=1`,
 * honoured ONLY for an actor who passes `can:rooms.manage` — same precedent
 * as `LocationController::index()`'s own `?include_inactive=1` (Tahap
 * 6.8.3), just gated on the newer named ability instead of the legacy
 * `can:admin` Gate, since `rooms.manage` is exactly "who may see/manage a
 * room outside the active-only default" for THIS resource (admin/
 * super_admin globally, unit_admin within their own already-enforced
 * `LocationScope`). Silently ignored for every other caller (operator/
 * viewer, and any caller that never sends the param — every existing
 * consumer of this route), so default behaviour is byte-identical to
 * before. Necessary because `unit_admin`/`super_admin` have no other way to
 * see a room they deactivated in order to reactivate it — the only other
 * "see inactive rooms" endpoint, `adminIndex()` above, admits only a
 * global-scope actor (R9.3), so it is never extended to unit_admin.
 */
class RoomController extends ApiController
{
    public function index(Request $request, Location $location): AnonymousResourceCollection
    {
        abort_if(! $location->is_active, 404);
        abort_if(! LocationScope::for($request->user())->allows($location->code), 404);

        $includeInactive = $request->boolean('include_inactive') && Gate::allows('rooms.manage');

        $rooms = $location->rooms()
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
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
     * `GET /api/rooms` (Tahap 6.8.1), `can:rooms.manage` + global scope (R9.3; was `can:admin`) — every room across every
     * location, active AND inactive, for the Master Data management list.
     * Unpaginated like every other master-data collection (docs/api_convention.md):
     * even with SD/SMP/SMA populated this stays a small, finite list.
     */
    public function adminIndex(Request $request): AnonymousResourceCollection
    {
        // Stage 6.9 R9.3 — route gate is `can:rooms.manage` (WHAT); this flat
        // every-location browser is not location-scoped, so only a GLOBAL-scope
        // actor may use it (WHERE). unit_admin holds `rooms.manage` but gets
        // 403 here, unchanged from the old `can:admin` gate.
        abort_unless(LocationScope::for($request->user())->isGlobal(), 403);

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

    /**
     * Multiple entry (Tahap 6.9 R10) — 1..100 rooms in one atomic request.
     *
     * Every distinct target location is authorized first, with the same
     * `RoomPolicy::create` check `store()` uses: one row outside the actor's
     * scope rejects the whole request (403) and nothing is written. All rows are
     * then inserted in ONE transaction, each exactly as `store()` would (always
     * active). `uq_rooms_location_name` stays the final word on duplicates: if a
     * row collides anyway (a room committed meanwhile, or two rows the column
     * collation treats as equal), the whole transaction rolls back and that row
     * gets the same message as the validation rule — never a partial batch.
     */
    public function storeEntries(StoreRoomEntriesRequest $request): JsonResponse
    {
        foreach ($request->locationCodes() as $locationCode) {
            if ($request->user()->cannot('create', [Room::class, $locationCode])) {
                abort(403);
            }
        }

        $created = DB::transaction(function () use ($request): Collection {
            $rooms = new Collection;
            foreach ($request->items() as $index => $item) {
                try {
                    $rooms->push(Room::create([...$item, 'is_active' => true]));
                } catch (QueryException $e) {
                    if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                        throw $e;
                    }

                    throw ValidationException::withMessages([
                        "items.{$index}.name" => ['Ruangan dengan nama ini sudah ada di lokasi tersebut.'],
                    ]);
                }
            }

            return $rooms;
        });

        $created->load('location');
        $count = $created->count();

        return RoomResource::collection($created)
            ->additional([
                'message' => "{$count} ruangan berhasil ditambahkan.",
                'count' => $count,
            ])
            ->response()
            ->setStatusCode(201);
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
