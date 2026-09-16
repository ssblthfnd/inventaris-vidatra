<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\DashboardResource;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\Request;

/**
 * Read-only aggregation for the (future) React dashboard (Tahap 5.6).
 *
 *   GET /api/dashboard   (can:viewer)
 *
 * Thin: all counting lives in {@see DashboardService}. No filters, no query
 * params — every role sees the SAME endpoint with no location parameter to
 * choose; what differs (Stage 6.9 R4) is purely who the acting user IS
 * (global role vs. `unit_admin`), which `DashboardService` resolves via
 * `App\Support\LocationScope`. There is no write side.
 */
class DashboardController extends ApiController
{
    public function index(Request $request, DashboardService $service): DashboardResource
    {
        return new DashboardResource($service->build($request->user()));
    }
}
