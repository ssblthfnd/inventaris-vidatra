<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\DashboardResource;
use App\Services\Dashboard\DashboardService;

/**
 * Read-only aggregation for the (future) React dashboard (Tahap 5.6).
 *
 *   GET /api/dashboard   (can:viewer)
 *
 * Thin: all counting lives in {@see DashboardService}. No filters, no query params —
 * this is the global dashboard. There is no write side.
 */
class DashboardController extends ApiController
{
    public function index(DashboardService $service): DashboardResource
    {
        return new DashboardResource($service->build());
    }
}
