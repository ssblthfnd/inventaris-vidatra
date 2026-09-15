<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreRoomAliasRequest;
use App\Http\Requests\Api\UpdateRoomAliasRequest;
use App\Http\Resources\RoomAliasResource;
use App\Import\Matching\RoomMatcher;
use App\Import\Parsing\ValueNormalizer;
use App\Models\RoomAlias;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Room alias management (Tahap 6.8.2).
 *
 * Unlike every other master-data write in this app (locations, categories,
 * subcategories, rooms — all `can:admin`, Tahap 6.8.1), room aliases are
 * `can:operator` for every verb including write. This is not a new decision:
 * `AuthServiceProvider`'s own docblock, written at Tahap 5.0 before any of
 * this code existed, already says "operator -> read + write assets /
 * mutations / imports / room aliases" vs. "admin -> ... structural
 * master-data management" — aliases are a day-to-day import-support tool
 * operators use directly, not structural data.
 *
 * `RoomAlias` has no `is_active` column and nothing references it (see the
 * migration's own docblock) — it is a pure leaf lookup table, so a real
 * `DELETE` is the correct lifecycle mechanism here, unlike every `is_active`
 * -toggle-only entity in 6.8.1. Deleting an alias never touches `assets` or
 * `rooms`.
 *
 * An alias is deliberately allowed to point to an INACTIVE room (create and
 * update) — this is intentional (e.g. admin cleanup after deactivating a
 * room that still has stale aliases pointing at it), not an oversight. What
 * an inactive room's alias can no longer do is resolve during NEW import
 * matching — see {@see RoomMatcher::load()}'s
 * `is_active` filter (Tahap 6.8.2, Part B).
 */
class RoomAliasController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $aliases = RoomAlias::query()
            ->with(['location', 'room', 'createdBy'])
            ->when($request->filled('location_code'), function ($query) use ($request) {
                $query->where('location_code', $request->string('location_code'));
            })
            ->when($request->filled('room_id'), function ($query) use ($request) {
                $query->where('room_id', $request->integer('room_id'));
            })
            ->when($request->filled('q'), function ($query) use ($request) {
                $like = '%'.$this->escapeLike((string) $request->string('q')).'%';
                $query->where('raw_value', 'like', $like);
            })
            ->orderBy('location_code')
            ->orderBy('raw_value')
            ->get();

        return RoomAliasResource::collection($aliases);
    }

    public function store(StoreRoomAliasRequest $request): JsonResponse
    {
        $alias = RoomAlias::create([
            ...$request->validated(),
            // explicit, not left to the DB column default: `create()` does not
            // re-read DB-assigned defaults back into the in-memory model, so
            // the immediately-returned resource would otherwise show `source`
            // as null even though the stored row is correctly 'manual'.
            'source' => $request->validated('source') ?? 'manual',
            'match_key' => ValueNormalizer::roomMatchKey($request->validated('raw_value')),
            'created_by' => $request->user()->id,
        ]);
        $alias->load(['location', 'room', 'createdBy']);

        return (new RoomAliasResource($alias))->response()->setStatusCode(201);
    }

    public function update(UpdateRoomAliasRequest $request, RoomAlias $roomAlias): RoomAliasResource
    {
        $validated = $request->validated();
        if (array_key_exists('raw_value', $validated)) {
            $validated['match_key'] = ValueNormalizer::roomMatchKey($validated['raw_value']);
        }

        $roomAlias->update($validated);
        $roomAlias->load(['location', 'room', 'createdBy']);

        return new RoomAliasResource($roomAlias);
    }

    public function destroy(RoomAlias $roomAlias): JsonResponse
    {
        $roomAlias->delete();

        return response()->json(['message' => 'Alias berhasil dihapus.']);
    }
}
