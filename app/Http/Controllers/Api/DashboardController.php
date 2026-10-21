<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\DashboardRoomsRequest;
use App\Http\Resources\DashboardResource;
use App\Http\Resources\DashboardRoomCollection;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\Request;

/**
 * Read-only aggregation for the React dashboard (Tahap 5.6).
 *
 *   GET /api/dashboard         (can:viewer)
 *   GET /api/dashboard/rooms   (can:viewer) — "Aset per Ruangan" table: search,
 *                              location filter, sort and pagination server-side
 *
 * Thin: all counting lives in {@see DashboardService}. `GET /api/dashboard` has no
 * filters or query params — every role sees the SAME endpoint with no location
 * parameter to choose; what differs (Stage 6.9 R4) is purely who the acting user
 * IS (global role vs. `unit_admin`), which `DashboardService` resolves via
 * `App\Support\LocationScope`. The rooms endpoint's `location_code` is narrowed by
 * that same scope. There is no write side.
 */
class DashboardController extends ApiController
{
    public function index(Request $request, DashboardService $service): DashboardResource
    {
        return new DashboardResource($service->build($request->user()));
    }

    public function rooms(DashboardRoomsRequest $request, DashboardService $service): DashboardRoomCollection
    {
        $search = $request->search();

        return new DashboardRoomCollection($service->roomPage(
            $request->user(),
            $search === null ? null : '%'.$this->escapeLike($search).'%',
            $request->locationCode(),
            $request->sort(),
            $request->perPage(),
        ));
    }
}
