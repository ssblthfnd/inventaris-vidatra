<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\FiltersAssets;
use App\Http\Requests\Api\AssetIndexRequest;
use App\Http\Resources\ReportResource;
use App\Services\Report\ReportService;

/**
 * `GET /api/reports/inventory` (Tahap 6.3), `can:operator` — a viewer is
 * forbidden here even though `GET /api/assets` is `can:viewer`, per this
 * stage's explicit authorization requirement.
 *
 * Deliberately reuses {@see AssetIndexRequest} + {@see FiltersAssets} — the
 * exact same filter vocabulary `GET /api/assets` and `GET /api/assets/export`
 * already use (Tahap 6.2's precedent) — so a filtered report means exactly what
 * the same filters mean on the Inventaris list. `page` / `per_page` / `sort` /
 * `direction` are accepted but unused (a report has no rows to page or order).
 *
 * Read-only and fully server-side aggregated ({@see ReportService}) — no raw
 * asset rows are ever fetched into PHP or returned to the client.
 */
class ReportController extends ApiController
{
    use FiltersAssets;

    public function index(AssetIndexRequest $request, ReportService $service): ReportResource
    {
        $assets = $this->assetsMatchingFilters($request);

        return new ReportResource($service->build($assets));
    }
}
