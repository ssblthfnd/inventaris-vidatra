<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\LocationResource;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only master data: locations. Only `is_active = true` records are visible
 * (no admin "show inactive" mechanism yet). Returned as a finite collection — see
 * docs/api_convention.md §Master data collections.
 */
class LocationController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $locations = Location::query()
            ->where('is_active', true)
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
}
