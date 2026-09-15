<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreLocationRequest;
use App\Http\Requests\Api\UpdateLocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Master data: locations.
 *
 * `index()`/`show()` (Tahap 5.3) stay read-only, active-only BY DEFAULT for
 * every role — untouched for every existing caller. `index()` gained exactly
 * one opt-in addition (Tahap 6.8.3): `?include_inactive=1`, honoured ONLY
 * when the actor passes `can:admin` — silently ignored otherwise, so a
 * non-admin (or any caller that never sends the param, which is every
 * existing consumer: `useMasterData()`, `MasterDataReadApiTest`) sees
 * byte-identical behaviour to before. This was chosen over a separate admin
 * endpoint (the pattern Tahap 6.8.1 used for `GET /api/rooms`) because
 * `locations` has no nested/scoped read route to free up a distinct path —
 * reusing `/api/locations` itself, strictly opt-in, avoids ever leaking
 * inactive locations into `useMasterData()`'s shared cache (which every
 * page — Inventory, Reports, AssetForm — reads from) for ANY role,
 * including admin, unless the Master Data page's own explicit request asks
 * for them.
 *
 * `store()`/`update()` (Tahap 6.8.3, `can:admin`) are new. No `destroy()` —
 * locations are structural master data; `is_active` is the only lifecycle
 * mechanism (same precedent as rooms). Deactivating a location performs NO
 * cascade: rooms, room_aliases, and assets referencing it are left
 * completely untouched (their own `restrictOnUpdate`/`restrictOnDelete` FKs
 * to `locations.code` don't care about `is_active` at all — that column
 * only gates NEW child-record creation, e.g. `StoreRoomRequest`'s "location
 * must be active" rule, and NEW import matching via `MasterData`, Tahap
 * 6.8.2). `code` is immutable — see StoreLocationRequest's docblock.
 */
class LocationController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $includeInactive = $request->boolean('include_inactive') && Gate::allows('admin');

        $locations = Location::query()
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->when($request->filled('q'), function ($query) use ($request) {
                $like = '%'.$this->escapeLike((string) $request->string('q')).'%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('alias', 'like', $like)->orWhere('code', 'like', $like));
            })
            ->orderBy('code')
            ->get();

        return LocationResource::collection($locations);
    }

    public function show(Location $location): LocationResource
    {
        abort_if(! $location->is_active, 404);

        return new LocationResource($location);
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        $location = Location::create([
            ...$request->validated(),
            'is_active' => true,
        ]);

        return (new LocationResource($location))->response()->setStatusCode(201);
    }

    public function update(UpdateLocationRequest $request, Location $location): LocationResource
    {
        $location->update($request->validated());

        return new LocationResource($location);
    }
}
