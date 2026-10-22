<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\FiltersAssets;
use App\Http\Requests\Api\AssetIndexRequest;
use App\Http\Resources\ReportResource;
use App\Services\Report\ReportPdfService;
use App\Services\Report\ReportService;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /api/reports/inventory` (Tahap 6.3), `can:assets.report` (Stage 6.9 R4; was `can:operator`) — a viewer is
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
 *
 * `GET /api/reports/inventory/pdf` (same gate, same request, same aggregates) is
 * the Laporan page's "Unduh PDF": the filters arrive as the same query string,
 * are validated again here, and are printed as a caption ({@see ReportPdfService}).
 */
class ReportController extends ApiController
{
    use FiltersAssets;

    public function index(AssetIndexRequest $request, ReportService $service): ReportResource
    {
        $assets = $this->assetsMatchingFilters($request);

        return new ReportResource($service->build($assets) + [
            'available_years' => $service->availableYears($request->user()),
        ]);
    }

    public function pdf(AssetIndexRequest $request, ReportService $service, ReportPdfService $pdf): Response
    {
        $report = $service->build($this->assetsMatchingFilters($request));
        $printedAt = CarbonImmutable::now(ReportPdfService::TIMEZONE);

        return $pdf->render($report, $request, $printedAt)->download($pdf->filename($printedAt));
    }
}
